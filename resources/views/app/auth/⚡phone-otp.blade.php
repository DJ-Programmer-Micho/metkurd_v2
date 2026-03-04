{{-- resources/views/app/auth/⚡phone-otp.blade.php --}}
<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use App\Models\CustomerProfile;

new #[Layout('app::layouts.app-auth')] class extends Component
{
    /** 0 = choose provider, 1 = enter code, 2 = edit phone */
    public int $flag = 0;

    public string $phone = '';
    public string $channel = '';

    public string $digit1 = '';
    public string $digit2 = '';
    public string $digit3 = '';
    public string $digit4 = '';
    public string $digit5 = '';
    public string $digit6 = '';

    // Settings
    protected int $otpTtlSeconds = 300;      // 5 minutes
    protected int $maxAttempts  = 5;        // lock after 5 wrong
    protected int $lockSeconds  = 600;      // 10 minutes
    protected int $cooldownSeconds = 60;    // resend cooldown

    // UI state (updated by tick())
    public int $expiresRemaining = 0;
    public int $cooldownRemaining = 0;
    public int $attemptsLeft = 5;
    public int $lockRemaining = 0;

    public function mount()
    {
        $user = Auth::guard('app')->user();

        // ✅ load phone reliably (even if relation isn't loaded/defined correctly)
        $this->phone = (string) (
            optional($user->profile)->phone_number
            ?? CustomerProfile::where('customer_id', $user->id)->value('phone_number')
            ?? ''
        );

        $this->syncState();
    }

    public function tick(): void
    {
        $this->syncState();
    }

    public function sendCode(string $channel)
    {
        $user = Auth::guard('app')->user();

        if ($user->phone_verify) {
            $this->dispatch('alert', type: 'info', message: 'Phone already verified.');
            return redirect()->to(route('app.home'));
        }

        $this->syncState();

        if ($this->isLocked) {
            $this->dispatch('alert', type: 'error', message: 'Too many attempts. Locked for '.$this->fmt($this->lockRemaining).'.');
            return;
        }

        if ($this->cooldownRemaining > 0) {
            $this->dispatch('alert', type: 'warning', message: 'Please wait '.$this->fmt($this->cooldownRemaining).' before resending.');
            return;
        }

        $this->validatePhoneOnly();
        $this->channel = $channel;

        // Generate + store OTP (DB)
        $otp = (string) random_int(100000, 999999);
        $user->phone_otp_number = $otp;
        $user->save();

        // Cache controls
        Cache::put($this->expiresKey(), now()->addSeconds($this->otpTtlSeconds)->timestamp, $this->otpTtlSeconds + 60);
        Cache::put($this->attemptsKey(), 0, $this->otpTtlSeconds + $this->lockSeconds + 600);
        Cache::put($this->cooldownKey(), now()->addSeconds($this->cooldownSeconds)->timestamp, $this->cooldownSeconds + 60);
        Cache::forget($this->lockKey()); // optional: clear old lock when new OTP sent

        // StandingTech expects recipient without '+'
        $recipient = ltrim($this->phone, '+');

        try {
            $this->sendStandingTechOtp($channel, $recipient, $otp);

            $this->flag = 1;
            $this->resetDigits();
            $this->syncState();

            $this->dispatch('alert', type: 'success', message: 'Code sent. Please check your phone.');
        } catch (\Throwable $e) {
            $this->dispatch('alert', type: 'error', message: 'Failed to send code. Please try another provider.');
        }
    }

    public function editPhone()
    {
        $this->flag = 2;
        $this->resetErrorBag();
        $this->syncState();
    }

    public function goBack()
    {
        $this->flag = 0;
        $this->resetErrorBag();
        $this->syncState();
    }

    public function savePhone()
    {
        $this->validatePhoneOnly();

        $user = Auth::guard('app')->user();

        // ✅ Unique check (ignore current customer's profile row)
        $this->validate([
            'phone' => [
                'required',
                'string',
                'regex:/^\+\d{10,15}$/',
                'max:30',
                Rule::unique('customer_profiles', 'phone_number')
                    ->ignore(CustomerProfile::where('customer_id', $user->id)->value('id')),
            ],
        ]);

        // ✅ NEVER create duplicates: update or create by customer_id
        CustomerProfile::updateOrCreate(
            ['customer_id' => $user->id],
            ['phone_number' => $this->phone]
        );

        // reset phone verification status since phone changed
        $user->phone_verify = false;
        $user->phone_verified_at = null;
        $user->phone_otp_number = null;
        $user->save();

        // clear any old state
        $this->clearOtpState();

        // refresh phone (in case UI shows old value)
        $this->phone = (string) CustomerProfile::where('customer_id', $user->id)->value('phone_number');

        $this->dispatch('alert', type: 'success', message: 'Phone updated. Choose a provider to receive a code.');
        $this->flag = 0;
    }

    public function confirm()
    {
        $this->syncState();

        if ($this->isLocked) {
            $this->dispatch('alert', type: 'error', message: 'Too many attempts. Locked for '.$this->fmt($this->lockRemaining).'.');
            return;
        }

        if ($this->isExpired) {
            $this->dispatch('alert', type: 'warning', message: 'Code expired. Please resend a new code.');
            return;
        }

        $this->validate([
            'digit1'=>['required','digits:1'],'digit2'=>['required','digits:1'],
            'digit3'=>['required','digits:1'],'digit4'=>['required','digits:1'],
            'digit5'=>['required','digits:1'],'digit6'=>['required','digits:1'],
        ]);

        $code = $this->digit1.$this->digit2.$this->digit3.$this->digit4.$this->digit5.$this->digit6;
        $user = Auth::guard('app')->user();

        if (hash_equals((string)($user->phone_otp_number ?? ''), $code)) {
            $user->phone_verify = true;
            $user->phone_verified_at = now();
            $user->phone_otp_number = null;
            $user->save();

            $this->clearOtpState();

            $this->dispatch('alert', type: 'success', message: 'Phone verified successfully! Redirecting…');
            return redirect()->to(route('app.home'));
        }

        // wrong code => attempts + lock
        $attempts = (int) Cache::get($this->attemptsKey(), 0);
        $attempts++;
        Cache::put($this->attemptsKey(), $attempts, $this->otpTtlSeconds + $this->lockSeconds + 600);

        if ($attempts >= $this->maxAttempts) {
            Cache::put($this->lockKey(), now()->addSeconds($this->lockSeconds)->timestamp, $this->lockSeconds + 60);
            $this->syncState();
            $this->dispatch('alert', type: 'error', message: 'Too many wrong attempts. Locked for '.$this->fmt($this->lockRemaining).'.');
            return;
        }

        $this->syncState();
        $this->dispatch('alert', type: 'error', message: 'Invalid code. Please try again.');
    }

    public function resend()
    {
        $this->syncState();

        if ($this->isLocked) {
            $this->dispatch('alert', type: 'error', message: 'Locked for '.$this->fmt($this->lockRemaining).'.');
            return;
        }

        if ($this->cooldownRemaining > 0) {
            $this->dispatch('alert', type: 'warning', message: 'Please wait '.$this->fmt($this->cooldownRemaining).' before requesting a new code.');
            return;
        }

        if (! $this->channel) {
            $this->dispatch('alert', type: 'info', message: 'Please choose a provider first.');
            $this->flag = 0;
            return;
        }

        $this->resetDigits();
        $this->sendCode($this->channel);
    }

    // =========================
    // StandingTech (send SAME OTP)
    // =========================
    private function sendStandingTechOtp(string $type, string $recipient, string $otp): array
    {
        $base   = config('services.standingtech.base',   env('STANDINGTECH_BASE_URL'));
        $token  = config('services.standingtech.token',  env('STANDINGTECH_TOKEN'));
        $sender = config('services.standingtech.sender', env('STANDINGTECH_SENDER_ID'));

        if (!$base || !$token || !$sender) {
            throw new \RuntimeException('StandingTech config missing (base/token/sender).');
        }

        $payload = [
            'recipient' => $recipient,     // no +
            'sender_id' => $sender,
            'type'      => $type,          // sms | whatsapp | telegram
            'message'   => (string) $otp,  // send OUR otp
            'lang'      => 'en',
        ];

        if ($type !== 'sms') {
            $payload['fallback'] = 'sms';
        }

        $res = Http::baseUrl($base)
            ->acceptJson()
            ->asJson()
            ->timeout(10)
            ->retry(2, 200)
            ->withToken($token)
            ->post('/api/v4/sms/send', $payload);

        $res->throw();
        return $res->json();
    }

    // =========================
    // Computed / Helpers
    // =========================
    public function fmt(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;
        return sprintf('%02d:%02d', $m, $s);
    }

    public function getIsExpiredProperty(): bool
    {
        return $this->flag === 1 && $this->expiresRemaining <= 0;
    }

    public function getIsLockedProperty(): bool
    {
        return $this->lockRemaining > 0;
    }

    public function getInputsDisabledProperty(): bool
    {
        return $this->isLocked || $this->isExpired;
    }

    private function syncState(): void
    {
        $uid = Auth::guard('app')->id();
        if (!$uid) return;

        $now = now()->timestamp;

        $expTs  = (int) Cache::get($this->expiresKey(), 0);
        $cdTs   = (int) Cache::get($this->cooldownKey(), 0);
        $lockTs = (int) Cache::get($this->lockKey(), 0);
        $attempts = (int) Cache::get($this->attemptsKey(), 0);

        $this->expiresRemaining  = $expTs  > 0 ? max(0, $expTs  - $now) : 0;
        $this->cooldownRemaining = $cdTs   > 0 ? max(0, $cdTs   - $now) : 0;
        $this->lockRemaining     = $lockTs > 0 ? max(0, $lockTs - $now) : 0;
        $this->attemptsLeft      = max(0, $this->maxAttempts - $attempts);
    }

    private function clearOtpState(): void
    {
        Cache::forget($this->expiresKey());
        Cache::forget($this->attemptsKey());
        Cache::forget($this->lockKey());
        Cache::forget($this->cooldownKey());
        $this->syncState();
    }

    private function resetDigits(): void
    {
        $this->digit1 = $this->digit2 = $this->digit3 = $this->digit4 = $this->digit5 = $this->digit6 = '';
    }

    private function validatePhoneOnly(): void
    {
        $digits = preg_replace('/\D+/', '', $this->phone ?? '');

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        $this->phone = $digits ? ('+' . $digits) : '';

        $this->validate([
            'phone' => [
                'required',
                'string',
                'regex:/^\+\d{10,15}$/',
                'max:30',
            ],
        ], [
            'phone.required' => 'Phone number is required.',
            'phone.regex' => 'Phone number must be in international format (+XXXXXXXXXXX).',
        ]);
    }

    private function expiresKey(): string  { return 'phone_otp_expires_'  . Auth::guard('app')->id(); }
    private function attemptsKey(): string { return 'phone_otp_attempts_' . Auth::guard('app')->id(); }
    private function lockKey(): string     { return 'phone_otp_lock_'     . Auth::guard('app')->id(); }
    private function cooldownKey(): string { return 'phone_otp_cooldown_' . Auth::guard('app')->id(); }
};

?>

<div class="row" wire:poll.1s="tick">
    <div class="col-lg-12">
        <div class="card overflow-hidden m-0" style="box-shadow: -12px -6px 45px 10px rgb(204 0 34 / 0.15);">
            <div class="row justify-content-center g-0">
                <div class="col-lg-6">
                    <div class="p-lg-5 p-4 auth-one-bg h-100">
                        <div class="bg-overlay"></div>
                        <div class="position-relative h-100 d-flex flex-column">
                            <div class="mb-4">
                                <a wire:navigate href="/" class="d-block">
                                    <img src="{{ app('logo_1024_tran') }}" alt="" height="25">
                                    MET KURD
                                </a>
                            </div>
                            <div class="mt-auto">
                                <div class="mb-3">
                                    <i class="ri-double-quotes-l display-4 text-success"></i>
                                </div>

                                <div id="qoutescarouselIndicators" class="carousel slide" data-bs-ride="carousel">
                                    <div class="carousel-indicators">
                                        <button type="button" data-bs-target="#qoutescarouselIndicators" data-bs-slide-to="0" class="active" aria-current="true"></button>
                                        <button type="button" data-bs-target="#qoutescarouselIndicators" data-bs-slide-to="1"></button>
                                        <button type="button" data-bs-target="#qoutescarouselIndicators" data-bs-slide-to="2"></button>
                                    </div>
                                    <div class="carousel-inner text-center text-white pb-5">
                                        <div class="carousel-item active">
                                            <p class="fs-15 fst-italic">" Verify your phone to protect your account. "</p>
                                        </div>
                                        <div class="carousel-item">
                                            <p class="fs-15 fst-italic">" Strong security is part of METKURD experience. "</p>
                                        </div>
                                        <div class="carousel-item">
                                            <p class="fs-15 fst-italic">" One more step and you're ready. "</p>
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="p-lg-5 p-4">
                        {{-- STATE 0: choose provider --}}
                        @if ($flag === 0)
                            <div class="mb-4">
                                <div class="avatar-lg mx-auto">
                                    <div class="avatar-title bg-light text-primary display-5 rounded-circle">
                                        <lord-icon
                                            src="https://cdn.lordicon.com/nnzfcuqw.json"
                                            trigger="loop"
                                            delay="1500"
                                            state="in-assembly"
                                            colors="primary:#cc0022,secondary:#ffffff"
                                            style="width:64px;height:64px">
                                        </lord-icon>
                                    </div>
                                </div>
                            </div>

                            <div class="text-muted text-center mx-lg-3 mb-4">
                                <h4><b>{{ $phone ?: '—' }}</b></h4>
                                <h5>Is This Your Phone number?</h5>
                                <small>Please choose one of the Providers</small>

                                {{-- ✅ show cooldown / lock even in provider screen --}}
                                <div class="mt-3 d-flex gap-2 justify-content-center flex-wrap">
                                    <span class="badge bg-warning text-dark">Attempts left: {{ $attemptsLeft }}</span>

                                    @if ($cooldownRemaining > 0)
                                        <span class="badge bg-secondary">Cooldown: {{ $this->fmt($cooldownRemaining) }}</span>
                                    @endif

                                    @if ($this->isLocked)
                                        <span class="badge bg-danger">Locked: {{ $this->fmt($lockRemaining) }}</span>
                                    @endif
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-4">
                                    <button class="btn btn-outline-success w-100"
                                            type="button"
                                            wire:click="sendCode('whatsapp')"
                                            wire:loading.attr="disabled"
                                            wire:target="sendCode"
                                            @disabled($this->isLocked || $cooldownRemaining > 0)>
                                        <span wire:loading.remove wire:target="sendCode">
                                            <img src="{{ app('whatsapp-logo') }}" width="30" alt=""> WhatsApp
                                        </span>
                                        <span wire:loading wire:target="sendCode"
                                              class="d-none align-items-center gap-1"
                                              wire:loading.class.remove="d-none"
                                              wire:loading.class="d-inline-flex">
                                            <span class="spinner-border spinner-border-sm"></span>
                                        </span>
                                    </button>
                                </div>

                                <div class="col-4">
                                    <button class="btn btn-outline-info w-100"
                                            type="button"
                                            wire:click="sendCode('telegram')"
                                            wire:loading.attr="disabled"
                                            wire:target="sendCode"
                                            @disabled($this->isLocked || $cooldownRemaining > 0)>
                                        <span wire:loading.remove wire:target="sendCode">
                                            <img src="{{ app('telegram-logo') }}" width="30" alt=""> Telegram
                                        </span>
                                        <span wire:loading wire:target="sendCode"
                                              class="d-none align-items-center gap-1"
                                              wire:loading.class.remove="d-none"
                                              wire:loading.class="d-inline-flex">
                                            <span class="spinner-border spinner-border-sm"></span>
                                        </span>
                                    </button>
                                </div>

                                <div class="col-4">
                                    <button class="btn btn-outline-secondary w-100"
                                            type="button"
                                            wire:click="sendCode('sms')"
                                            wire:loading.attr="disabled"
                                            wire:target="sendCode"
                                            @disabled($this->isLocked || $cooldownRemaining > 0)>
                                        <span wire:loading.remove wire:target="sendCode">
                                            <img src="{{ app('sms-logo') }}" width="30" alt=""> SMS
                                        </span>
                                        <span wire:loading wire:target="sendCode"
                                              class="d-none align-items-center gap-1"
                                              wire:loading.class.remove="d-none"
                                              wire:loading.class="d-inline-flex">
                                            <span class="spinner-border spinner-border-sm"></span>
                                        </span>
                                    </button>
                                </div>

                                <div class="col-12 mt-3">
                                    <button class="btn btn-danger w-100" type="button"
                                            wire:click="editPhone"
                                            wire:loading.attr="disabled"
                                            wire:target="editPhone">
                                        <span wire:loading.remove wire:target="editPhone">No, Let me update it</span>
                                        <span wire:loading wire:target="editPhone"
                                              class="d-none align-items-center gap-2"
                                              wire:loading.class.remove="d-none"
                                              wire:loading.class="d-inline-flex">
                                            <span class="spinner-border spinner-border-sm"></span> Opening…
                                        </span>
                                    </button>
                                </div>
                            </div>

                        {{-- STATE 2: edit phone --}}
                        @elseif ($flag === 2)
                            <div class="mb-4">
                                <div class="avatar-lg mx-auto">
                                    <div class="avatar-title bg-light text-primary display-5 rounded-circle">
                                        <lord-icon
                                            src="https://cdn.lordicon.com/fikcyfpp.json"
                                            trigger="loop"
                                            colors="primary:#cc0022,secondary:#ffffff"
                                            style="width:64px;height:64px">
                                        </lord-icon>
                                    </div>
                                </div>
                            </div>

                            <div class="text-muted text-center mx-lg-3 mb-3">
                                <h5>Edit Your Phone Number</h5>
                            </div>

                            <div class="mb-3">
                                <label for="phone" class="form-label">Phone <span class="text-danger">*</span></label>
                                <input type="tel"
                                       class="form-control"
                                       id="phone"
                                       wire:model.defer="phone"
                                       placeholder="+9647500000000"
                                       inputmode="tel"
                                       required>
                                @error('phone') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                            </div>

                            <div class="d-flex gap-2">
                                <button class="btn btn-primary" type="button"
                                        wire:click="savePhone"
                                        wire:loading.attr="disabled"
                                        wire:target="savePhone">
                                    <span wire:loading.remove wire:target="savePhone">Save & Choose Provider</span>
                                    <span wire:loading wire:target="savePhone"
                                          class="d-none align-items-center gap-2"
                                          wire:loading.class.remove="d-none"
                                          wire:loading.class="d-inline-flex">
                                        <span class="spinner-border spinner-border-sm"></span> Saving…
                                    </span>
                                </button>

                                <button class="btn btn-secondary" type="button"
                                        wire:click="goBack"
                                        wire:loading.attr="disabled"
                                        wire:target="goBack">
                                    <span wire:loading.remove wire:target="goBack">Cancel</span>
                                    <span wire:loading wire:target="goBack"
                                          class="d-none align-items-center gap-2"
                                          wire:loading.class.remove="d-none"
                                          wire:loading.class="d-inline-flex">
                                        <span class="spinner-border spinner-border-sm"></span> Closing…
                                    </span>
                                </button>
                            </div>

                        {{-- STATE 1: enter code --}}
                        @else
                            <div class="mb-4">
                                <div class="avatar-lg mx-auto">
                                    <div class="avatar-title bg-light text-primary display-5 rounded-circle">
                                        <lord-icon
                                            src="https://cdn.lordicon.com/dhzbkemf.json"
                                            trigger="loop"
                                            colors="primary:#cc0022,secondary:#ffffff"
                                            style="width:64px;height:64px">
                                        </lord-icon>
                                    </div>
                                </div>
                            </div>

                            <div class="text-muted text-center mx-lg-3">
                                <h4>Please enter the 6 digit code sent to <b>{{ $phone }}</b></h4>

                                {{-- ✅ show all state badges in-card --}}
                                <div class="mt-3 d-flex gap-2 justify-content-center flex-wrap">
                                    <span class="badge bg-info">Expires in: {{ $this->fmt($expiresRemaining) }}</span>
                                    <span class="badge bg-warning text-dark">Attempts left: {{ $attemptsLeft }}</span>

                                    @if ($cooldownRemaining > 0)
                                        <span class="badge bg-secondary">Cooldown: {{ $this->fmt($cooldownRemaining) }}</span>
                                    @endif

                                    @if ($this->isExpired)
                                        <span class="badge bg-danger">Expired</span>
                                    @endif

                                    @if ($this->isLocked)
                                        <span class="badge bg-danger">Locked: {{ $this->fmt($lockRemaining) }}</span>
                                    @endif
                                </div>
                            </div>

                            <div class="mt-4">
                                <form autocomplete="off" onsubmit="return false;">
                                    <div class="row">
                                        @foreach (['digit1','digit2','digit3','digit4','digit5','digit6'] as $i => $model)
                                            <div class="col-2">
                                                <div class="mb-3">
                                                    <label for="digit{{ $i+1 }}-input" class="visually-hidden">Digit {{ $i+1 }}</label>
                                                    <input type="text"
                                                           class="form-control form-control-lg bg-light border-light text-center"
                                                           wire:model.defer="{{ $model }}"
                                                           onkeyup="moveToNext({{ $i+1 }}, event)"
                                                           maxlength="1"
                                                           id="digit{{ $i+1 }}-input"
                                                           @disabled($this->inputsDisabled)>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>

                                    <div class="mt-3">
                                        <button type="button" class="btn btn-success w-100"
                                                wire:click="confirm"
                                                wire:loading.attr="disabled"
                                                wire:target="confirm"
                                                @disabled($this->inputsDisabled)>
                                            <span wire:loading.remove wire:target="confirm">Confirm</span>
                                            <span wire:loading wire:target="confirm"
                                                  class="d-none align-items-center gap-2"
                                                  wire:loading.class.remove="d-none"
                                                  wire:loading.class="d-inline-flex">
                                                <span class="spinner-border spinner-border-sm"></span> Verifying…
                                            </span>
                                        </button>
                                    </div>
                                </form>
                            </div>

                            <div class="mt-3 text-center">
                                <p class="mb-0">Didn't receive a code?
                                    <a href="#"
                                       wire:click.prevent="resend"
                                       wire:loading.attr="disabled"
                                       wire:target="resend"
                                       class="fw-semibold text-primary text-decoration-underline {{ ($cooldownRemaining > 0 || $this->isLocked) ? 'pe-none opacity-50' : '' }}">
                                        {{ $cooldownRemaining > 0 ? "Resend in ".$this->fmt($cooldownRemaining) : 'Resend' }}
                                    </a>
                                </p>
                            </div>
                        @endif
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
window.moveToNext = function (index, e) {
  const id = 'digit' + index + '-input';
  const input = document.getElementById(id);
  if (!input || input.disabled) return;

  const key = e.key || '';
  if (/^\d$/.test(input.value)) {
    const next = document.getElementById('digit' + (index + 1) + '-input');
    if (next && !next.disabled) next.focus();
  } else if (key === 'Backspace') {
    const prev = document.getElementById('digit' + (index - 1) + '-input');
    if (prev && !prev.disabled) prev.focus();
  } else {
    input.value = input.value.replace(/\D/g, '').slice(0,1);
  }
}
</script>
@endpush