{{-- resources/views/app/auth/⚡email-otp.blade.php --}}
<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

new #[Layout('app::layouts.app-auth')] class extends Component
{
    /** 0 = confirm email, 1 = enter code, 2 = edit email */
    public int $flag = 0;

    public string $email = '';
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
        $this->email = (string) Auth::guard('app')->user()->email;
        $this->syncState();
    }

    public function tick(): void
    {
        $this->syncState();
    }

    public function verifyEmail()
    {
        $user = Auth::guard('app')->user();

        if ($user->email_verify) {
            $this->dispatch('alert', type: 'info', message: 'Email already verified.');
            return redirect()->to(route('app.phone.otp'));
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

        $otp = (string) random_int(100000, 999999);

        $user->email_otp_number = $otp;
        $user->save();

        Cache::put($this->expiresKey(), now()->addSeconds($this->otpTtlSeconds)->timestamp, $this->otpTtlSeconds + 60);
        Cache::put($this->attemptsKey(), 0, $this->otpTtlSeconds + $this->lockSeconds + 600);
        Cache::put($this->cooldownKey(), now()->addSeconds($this->cooldownSeconds)->timestamp, $this->cooldownSeconds + 60);

        Mail::to($this->email)->send(new \App\Mail\VerifyRegisterMail($otp));

        $this->flag = 1;
        $this->resetDigits();
        $this->syncState();

        $this->dispatch('alert', type: 'success', message: 'We sent you a 6-digit code via email.');
    }

    public function editEmail() { $this->flag = 2; $this->syncState(); }
    public function goBack() { $this->flag = 0; $this->syncState(); }

    public function saveEmail()
    {
        $this->validate([
            'email' => ['required','email','max:255', Rule::unique('customers','email')->ignore(Auth::guard('app')->id())],
        ]);

        $user = Auth::guard('app')->user();
        $user->email = $this->email;
        $user->email_verify = false;
        $user->email_verified_at = null;
        $user->email_otp_number = null;
        $user->save();

        $this->clearOtpState();

        $this->dispatch('alert', type: 'success', message: 'Email updated. Sending a new code…');
        $this->verifyEmail();
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

        if (hash_equals((string)($user->email_otp_number ?? ''), $code)) {
            $user->email_verify = true;
            $user->email_verified_at = now();
            $user->email_otp_number = null;
            $user->save();

            $this->clearOtpState();

            $this->dispatch('alert', type: 'success', message: 'Email verified! Moving to phone verification…');
            return redirect()->to(route('app.phone.otp'));
        }

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

        $this->verifyEmail();
        $this->dispatch('alert', type: 'info', message: 'A new code was sent.');
    }

    // Helpers
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

    private function expiresKey(): string  { return 'email_otp_expires_'  . Auth::guard('app')->id(); }
    private function attemptsKey(): string { return 'email_otp_attempts_' . Auth::guard('app')->id(); }
    private function lockKey(): string     { return 'email_otp_lock_'     . Auth::guard('app')->id(); }
    private function cooldownKey(): string { return 'email_otp_cooldown_' . Auth::guard('app')->id(); }
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
                            <div class="mt-auto text-white">
                                <p class="fs-15 fst-italic text-center">" One more step and you're ready. "</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="p-lg-5 p-4">

                        @if ($flag === 0)
                            <div class="text-muted text-center mx-lg-3 mb-5">
                                <h4><b>{{ $email }}</b></h4>
                                <h5>Is This Your Email?</h5>
                            </div>

                            <div class="row">
                                <div class="col-6">
                                    <button class="btn btn-success w-100" type="button"
                                            wire:click="verifyEmail"
                                            wire:loading.attr="disabled"
                                            wire:target="verifyEmail">
                                        <span wire:loading.remove wire:target="verifyEmail">Yes, Send me code</span>
                                        <span wire:loading wire:target="verifyEmail">
                                            <span class="spinner-border spinner-border-sm me-2"></span> Sending…
                                        </span>
                                    </button>
                                </div>
                                <div class="col-6">
                                    <button class="btn btn-danger w-100" type="button"
                                            wire:click="editEmail"
                                            wire:loading.attr="disabled"
                                            wire:target="editEmail">
                                        <span wire:loading.remove wire:target="editEmail">No, Let me update it</span>
                                        <span wire:loading wire:target="editEmail">
                                            <span class="spinner-border spinner-border-sm me-2"></span> Opening…
                                        </span>
                                    </button>
                                </div>
                            </div>

                        @elseif ($flag === 2)
                            <div class="text-muted text-center mx-lg-3 mb-3">
                                <h5>Edit Your Email</h5>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Email *</label>
                                <input type="email" class="form-control" wire:model.defer="email">
                                @error('email') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                            </div>

                            <div class="d-flex gap-2">
                                <button class="btn btn-primary" type="button"
                                        wire:click="saveEmail"
                                        wire:loading.attr="disabled"
                                        wire:target="saveEmail">
                                    <span wire:loading.remove wire:target="saveEmail">Save & Send Code</span>
                                    <span wire:loading wire:target="saveEmail">
                                        <span class="spinner-border spinner-border-sm me-2"></span> Working…
                                    </span>
                                </button>

                                <button class="btn btn-secondary" type="button"
                                        wire:click="goBack"
                                        wire:loading.attr="disabled"
                                        wire:target="goBack">
                                    <span wire:loading.remove wire:target="goBack">Cancel</span>
                                    <span wire:loading wire:target="goBack">
                                        <span class="spinner-border spinner-border-sm me-2"></span> Closing…
                                    </span>
                                </button>
                            </div>

                        @else
                            {{-- ✅ Timers shown INSIDE the card --}}
                            <div class="text-muted text-center mx-lg-3">
                                <h4>Please enter the 6 digit code sent to <b>{{ $email }}</b></h4>

                                <div class="mt-3 d-flex gap-2 justify-content-center flex-wrap">
                                    <span class="badge bg-info">Expires in: {{ $this->fmt($expiresRemaining) }}</span>
                                    <span class="badge bg-warning text-dark">Attempts left: {{ $attemptsLeft }}</span>

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
                                                    <input type="text"
                                                           id="d{{ $i+1 }}"
                                                           maxlength="1"
                                                           class="form-control form-control-lg bg-light border-light text-center"
                                                           wire:model.defer="{{ $model }}"
                                                           onkeyup="moveToNext({{ $i+1 }}, event)"
                                                           @disabled($this->inputsDisabled)>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>

                                    <div class="mt-3">
                                        <button class="btn btn-success w-100" type="button"
                                                wire:click="confirm"
                                                wire:loading.attr="disabled"
                                                wire:target="confirm"
                                                @disabled($this->inputsDisabled)>
                                            <span wire:loading.remove wire:target="confirm">Confirm</span>
                                            <span wire:loading wire:target="confirm">
                                                <span class="spinner-border spinner-border-sm me-2"></span> Verifying…
                                            </span>
                                        </button>
                                    </div>
                                </form>
                            </div>

                            <div class="mt-3 text-center">
                                <a href="#"
                                   wire:click.prevent="resend"
                                   wire:loading.attr="disabled"
                                   wire:target="resend"
                                   class="fw-semibold text-primary text-decoration-underline {{ ($cooldownRemaining > 0 || $this->isLocked) ? 'pe-none opacity-50' : '' }}">
                                    {{ $cooldownRemaining > 0 ? "Resend in ".$this->fmt($cooldownRemaining) : 'Resend' }}
                                </a>
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
    const input = document.getElementById('d' + index);
    if (!input || input.disabled) return;

    const key = e.key || '';
    if (/^\d$/.test(input.value)) {
        const next = document.getElementById('d' + (index + 1));
        if (next && !next.disabled) next.focus();
    } else if (key === 'Backspace') {
        const prev = document.getElementById('d' + (index - 1));
        if (prev && !prev.disabled) prev.focus();
    } else {
        input.value = input.value.replace(/\D/g, '').slice(0, 1);
    }
};
</script>
@endpush