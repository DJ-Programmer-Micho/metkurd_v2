<div class="page-content">
    @push('styles')
        <style>
            .profile-wid-bg::before {
                content: "";
                position: absolute;
                left: 0;
                right: 0;
                top: 0;
                bottom: 0;
                opacity: .7;
                background: #cc0022;
                background: linear-gradient(to top, #cc0022, #000);
            }
            .otp-input { font-size: 1.5rem; font-weight: bold; height: 50px; }
        </style>
    @endpush

    <div class="container-fluid">
        {{-- Header background --}}
        <div class="profile-foreground position-relative mx-n4 mt-n4">
            <div class="profile-wid-bg">
                <img src="https://images.pexels.com/photos/3389614/pexels-photo-3389614.jpeg"
                     alt="" class="profile-wid-img" />
            </div>
        </div>

        {{-- Top section --}}
        <div class="pt-4 mb-4 mb-lg-3 pb-lg-4 profile-wrapper">
            <div class="row g-4">
                <div class="col-auto">
                    <div class="avatar-lg">
                        <img
                            src="{{ $this->currentAvatarUrl() }}"
                            alt="{{ ($profile?->first_name ?? '').' '.($profile?->last_name ?? '') }}"
                            class="img-thumbnail rounded-circle"
                        />
                    </div>
                </div>

                <div class="col">
                    <div class="p-2">
                        <h3 class="text-white mb-1">
                            {{ ($profile?->first_name ?? '').' '.($profile?->last_name ?? '') }}
                        </h3>
                        <p class="text-white text-opacity-75">
                            {{ $profile?->job_title }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        {{-- Content --}}
        <div class="row">
            <div class="col-xxl-3">
                {{-- Info --}}
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title mb-3">{{ __('Info') }}</h5>
                        <div class="table-responsive">
                            <table class="table table-borderless mb-0">
                                <tbody>
                                <tr>
                                    <th class="ps-0" scope="row">{{ __('Full Name :') }}</th>
                                    <td class="text-muted">
                                        {{ ($profile?->first_name ?? '').' '.($profile?->last_name ?? '') }}
                                    </td>
                                </tr>
                                <tr>
                                    <th class="ps-0" scope="row">{{ __('Mobile :') }}</th>
                                    <td class="text-muted">{{ $profile?->phone_number }}</td>
                                </tr>
                                <tr>
                                    <th class="ps-0" scope="row">{{ __('E-mail :') }}</th>
                                    <td class="text-muted">{{ $user?->email }}</td>
                                </tr>
                                <tr>
                                    <th class="ps-0" scope="row">{{ __('Joining Date :') }}</th>
                                    <td class="text-muted">{{ $user?->created_at }}</td>
                                </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                {{-- Subscription (placeholder) --}}
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title mb-3">{{ __('Subscription') }}</h5>
                        <div class="table-responsive">
                            <table class="table table-borderless mb-0">
                                <tbody>
                                <tr>
                                    <th class="ps-0" scope="row">{{ __('Type :') }}</th>
                                    <td class="text-muted">{{ __('Premium') }}</td>
                                </tr>
                                <tr>
                                    <th class="ps-0" scope="row">{{ __('Started Date :') }}</th>
                                    <td class="text-muted">—</td>
                                </tr>
                                <tr>
                                    <th class="ps-0" scope="row">{{ __('Expire Date :') }}</th>
                                    <td class="text-muted">—</td>
                                </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                {{-- Features (placeholder) --}}
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title mb-4">{{ __('Features To Use :') }}</h5>
                        <ul class="p-1">
                            <li class="mb-1 badge bg-primary-subtle text-primary" style="font-size: 0.90em">{{ __('TEXT-TO-SPEECH') }}</li>
                            <li class="mb-1 badge bg-info-subtle text-info" style="font-size: 0.90em">{{ __('SPEECH-TO-TEXT / ASR') }}</li>
                            <li class="mb-1 badge bg-danger-subtle text-danger" style="font-size: 0.90em">{{ __('AUDIO SPLITTER') }}</li>
                            <li class="mb-1 badge bg-success-subtle text-success" style="font-size: 0.90em">{{ __('AUDIO SEPARATOR') }}</li>
                            <li class="mb-1 badge bg-warning-subtle text-warning" style="font-size: 0.90em">{{ __('OCR') }}</li>
                        </ul>
                    </div>
                </div>
            </div>

            {{-- Right side --}}
            <div class="col-xxl-9 mt-5">
                <div class="card mt-xxl-n5">
                    <div class="card-header">
                        <ul class="nav nav-tabs-custom rounded card-header-tabs border-bottom-0" role="tablist">
                            <li class="nav-item" wire:ignore>
                                <a class="nav-link text-body active" data-bs-toggle="tab" href="#personalDetails" role="tab">
                                    <i class="fas fa-home"></i> {{ __('Personal Details') }}
                                </a>
                            </li>
                            <li class="nav-item" wire:ignore>
                                <a class="nav-link text-body" data-bs-toggle="tab" href="#changePassword" role="tab">
                                    <i class="far fa-user"></i> {{ __('Change Password') }}
                                </a>
                            </li>
                        </ul>
                    </div>

                    <div class="card-body p-4">
                        <div class="tab-content">

                            {{-- Personal details --}}
                            <div wire:ignore.self class="tab-pane active" id="personalDetails" role="tabpanel">
                                <div class="row">
                                    <div class="col-lg-6 mb-3">
                                        <label class="form-label">{{ __('First Name') }}</label>
                                        <input type="text" class="form-control" value="{{ $profile?->first_name }}" disabled>
                                    </div>

                                    <div class="col-lg-6 mb-3">
                                        <label class="form-label">{{ __('Last Name') }}</label>
                                        <input type="text" class="form-control" value="{{ $profile?->last_name }}" disabled>
                                    </div>

                                    <div class="col-lg-6 mb-3">
                                        <label class="form-label">{{ __('Username') }}</label>
                                        <input type="text" class="form-control" value="{{ $user?->username }}" disabled>
                                    </div>

                                    <div class="col-lg-6 mb-3">
                                        <label class="form-label">{{ __('Job Title') }}</label>
                                        <input type="text" class="form-control" value="{{ $profile?->job_title }}" disabled>
                                    </div>

                                    <div class="col-lg-6 mb-3">
                                        <label class="form-label">{{ __('Phone Number') }}</label>
                                        <input type="text" class="form-control" value="{{ $profile?->phone_number }}" disabled>
                                    </div>

                                    <div class="col-lg-6 mb-3">
                                        <label class="form-label">{{ __('Email Address') }}</label>
                                        <input type="email" class="form-control" value="{{ $user?->email }}" disabled>
                                    </div>

                                    <div class="col-lg-12">
                                        <div class="hstack gap-2 justify-content-end">
                                            {{-- Instant open (JS), then Livewire prepares fields in background --}}
                                            <button type="button" class="btn btn-primary" onclick="window.ProfilePage.openEditModal()">
                                                {{ __('Edit') }}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Change password (inline) --}}
                            <div wire:ignore.self class="tab-pane" id="changePassword" role="tabpanel">
                                <form wire:submit.prevent="updatePassword">
                                    <div class="row g-2">
                                        <div class="col-lg-4">
                                            <label class="form-label">Old Password*</label>
                                            <input type="password" wire:model.defer="old_password" class="form-control" placeholder="Enter current password">
                                            @error('old_password') <span class="text-danger">{{ $message }}</span> @enderror
                                            <div class="mt-2">
                                                Forgot Password? <a href="{{ route('app.password.email') }}" class="text-danger">Send reset link to my email</a>
                                            </div>
                                        </div>

                                        <div class="col-lg-4">
                                            <label class="form-label">New Password*</label>
                                            <input type="password" wire:model.defer="new_password" id="newpasswordInput" class="form-control" placeholder="Enter new password">
                                            @error('new_password') <span class="text-danger">{{ $message }}</span> @enderror
                                        </div>

                                        <div class="col-lg-4">
                                            <label class="form-label">Confirm Password*</label>
                                            <input type="password" wire:model.defer="new_password_confirmation" class="form-control" placeholder="Confirm password">
                                            @error('new_password_confirmation') <span class="text-danger">{{ $message }}</span> @enderror
                                        </div>

                                        <div class="col-lg-12">
                                            <div id="password-contain" class="p-3 bg-light mb-3 rounded">
                                                <h5 class="fs-13">Password must contain:</h5>
                                                <p id="pass-lower"   class="invalid fs-12 mb-2"> At least one lowercase letter</p>
                                                <p id="pass-upper"   class="invalid fs-12 mb-2"> At least one uppercase letter</p>
                                                <p id="pass-number"  class="invalid fs-12 mb-2"> At least one number</p>
                                                <p id="pass-special" class="invalid fs-12 mb-2"> At least one special character</p>
                                                <p id="pass-length"  class="invalid fs-12 mb-0"> At least 8 characters</p>
                                            </div>
                                        </div>

                                        <div class="col-lg-12">
                                            <div class="text-end">
                                                <button type="submit" class="btn btn-success">Change Password</button>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>

                        </div> {{-- tab-content --}}
                    </div>
                </div>
            </div>

        </div> {{-- row --}}
    </div> {{-- container --}}

    {{-- Edit user modal --}}
    <div wire:ignore.self class="modal fade overflow-auto" id="updateUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog text-white mx-1 mx-lg-auto">
            <div class="modal-content bg-dark">
                <form wire:submit.prevent="updateUser">
                    <div class="modal-body">
                        <div class="modal-header mb-3">
                            <h5 class="modal-title">{{ __('Edit User') }}</h5>
                            <button type="button" class="btn btn-danger" onclick="window.ProfilePage.closeModal('updateUserModal')" aria-label="Close">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>

                        <hr class="bg-white">

                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label">{{ __('First Name') }}</label>
                                <input type="text" class="form-control @error('fNameEdit') is-invalid @enderror" wire:model.defer="fNameEdit">
                                @error('fNameEdit') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                            </div>

                            <div class="col-6 mb-3">
                                <label class="form-label">{{ __('Last Name') }}</label>
                                <input type="text" class="form-control @error('lNameEdit') is-invalid @enderror" wire:model.defer="lNameEdit">
                                @error('lNameEdit') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                            </div>

                            <div class="col-6 mb-3">
                                <label class="form-label">{{ __('Username') }}</label>
                                <input type="text" class="form-control @error('usernameEdit') is-invalid @enderror" wire:model.defer="usernameEdit">
                                @error('usernameEdit') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                            </div>

                            <div class="col-6 mb-3">
                                <label class="form-label">{{ __('Phone') }}</label>
                                <div class="input-group">
                                    <input type="text"
                                           class="form-control @error('phoneEdit') is-invalid @enderror"
                                           wire:model.lazy="phoneEdit"
                                           oninput="this.value = this.value.replace(/[^0-9+]/g, '');"
                                    >

                                    @if($phoneChanged && !$phoneVerified)
                                        <button type="button" class="btn btn-warning" wire:click="openPhoneOtpProviders">
                                            <i class="ri-shield-check-line"></i> Verify
                                        </button>
                                    @endif

                                    @if($phoneVerified)
                                        <span class="input-group-text bg-success text-white">
                                            <i class="ri-checkbox-circle-fill"></i> Verified
                                        </span>
                                    @endif
                                </div>

                                @if($phoneChanged && !$phoneVerified)
                                    <small class="text-warning">
                                        <i class="ri-alert-line"></i> Phone number changed. Please verify before saving.
                                    </small>
                                @endif

                                @error('phoneEdit') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">{{ __('Job Title') }}</label>
                                <select class="form-select @error('jobTitleEdit') is-invalid @enderror" wire:model.defer="jobTitleEdit">
                                    @foreach($jobTitleOptions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('jobTitleEdit') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                            </div>

                            <div class="col-12 mb-3">
                                <label class="form-label">{{ __('Email Address') }}</label>
                                <input type="email" class="form-control bg-dark text-muted" wire:model.defer="emailEdit" readonly disabled>
                                <small class="text-muted">
                                    <i class="ri-lock-line"></i> Email cannot be changed for security reasons.
                                </small>
                            </div>

                            <div class="col-12 mb-3">
                                <div class="d-flex align-items-center gap-3">
                                    <img src="{{ $this->currentAvatarUrl() }}"
                                         class="rounded-circle"
                                         style="width:60px;height:60px;object-fit:cover;border:1px solid #444;">
                                    <div class="flex-grow-1">
                                        <label class="form-label mb-1">{{ __('Avatar') }}</label>
                                        <input type="file" class="form-control" wire:model="avatar" accept="image/*">
                                        @error('avatar') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                                    </div>
                                </div>
                            </div>

                        </div> {{-- row --}}
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="window.ProfilePage.closeModal('updateUserModal')">{{ __('Close') }}</button>
                        <button type="submit" class="btn btn-primary">{{ __('Update') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Phone OTP modal --}}
    <div wire:ignore.self class="modal fade" id="phoneOtpModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="ri-shield-check-line text-primary"></i> Verify Phone Number
                    </h5>
                    <button type="button" class="btn-close" wire:click="closePhoneOtpModal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="text-center mb-3">
                        <p class="text-muted">We'll send a verification code to:</p>
                        <h6 class="text-primary">{{ $phoneEdit }}</h6>
                    </div>

                    @if($otpStep === 0)
                        <div class="d-grid gap-2">
                            <button type="button" class="btn btn-outline-success" wire:click="sendPhoneOtp('sms')">
                                <i class="ri-message-2-line"></i> Send via SMS
                            </button>
                            <button type="button" class="btn btn-outline-primary" wire:click="sendPhoneOtp('whatsapp')">
                                <i class="ri-whatsapp-line"></i> Send via WhatsApp
                            </button>
                            <button type="button" class="btn btn-outline-info" wire:click="sendPhoneOtp('telegram')">
                                <i class="ri-telegram-line"></i> Send via Telegram
                            </button>
                        </div>
                    @endif

                    @if($otpStep === 1)
                        <p class="text-center text-muted mb-3">
                            Enter the 6-digit code sent via <strong>{{ ucfirst($channel) }}</strong>
                        </p>

                        <div class="row justify-content-center mb-3">
                            @foreach([1,2,3,4,5,6] as $i)
                                <div class="col-2 px-1">
                                    <input type="text"
                                           class="form-control text-center otp-input"
                                           maxlength="1"
                                           wire:model.defer="digit{{ $i }}"
                                           id="otp{{ $i }}">
                                </div>
                            @endforeach
                        </div>

                        <div class="d-grid gap-2">
                            <button type="button" class="btn btn-success" wire:click="verifyPhoneOtp">
                                <i class="ri-check-line"></i> Verify Code
                            </button>
                            <button type="button" class="btn btn-link text-muted" wire:click="resendPhoneOtp">
                                <i class="ri-refresh-line"></i> Resend Code
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="backToProviders">
                                <i class="ri-arrow-left-line"></i> Back to providers
                            </button>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
    @once
    <script>
        document.addEventListener('livewire:init', () => {

            // -------------------------------
            // Bootstrap modal helpers
            // -------------------------------
            function showModal(id, opts = {}) {
                const el = document.getElementById(id);
                if (!el) return;
                bootstrap.Modal.getOrCreateInstance(el, opts).show();
            }

            function hideModal(id) {
                const el = document.getElementById(id);
                if (!el) return;
                const inst = bootstrap.Modal.getInstance(el) || bootstrap.Modal.getOrCreateInstance(el);
                inst.hide();

                // Cleanup leftover backdrop
                setTimeout(() => {
                    document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
                    document.body.classList.remove('modal-open');
                    document.body.style.removeProperty('padding-right');
                }, 150);
            }

            // Server-driven show/hide
            Livewire.on('bs:modal:show', ({ id }) => {
                if (!id) return;
                // If you want static for OTP only, keep default here and pass opts from server if needed.
                showModal(id);
            });

            Livewire.on('bs:modal:hide', ({ id }) => {
                if (!id) return;
                hideModal(id);
            });

            // Client-driven instant open (no network roundtrip)
            window.ProfilePage = {
                openEditModal() {
                    showModal('updateUserModal');

                    // Prepare values in background (safe in Volt/LW3/4)
                    try {
                        @this.call('prepareEditForm');
                    } catch (e) {}
                },
                closeModal(id) {
                    hideModal(id);
                }
            };

            // -------------------------------
            // OTP auto-advance + paste
            // -------------------------------
            function initOtpInputs() {
                const otpInputs = document.querySelectorAll('.otp-input');
                if (!otpInputs.length) return;

                otpInputs.forEach((input, index) => {
                    input.addEventListener('input', function () {
                        this.value = this.value.replace(/[^0-9]/g, '');
                        if (this.value.length === 1 && index < otpInputs.length - 1) {
                            otpInputs[index + 1].focus();
                        }
                    });

                    input.addEventListener('keydown', function (e) {
                        if (e.key === 'Backspace' && !this.value && index > 0) {
                            otpInputs[index - 1].focus();
                        }
                    });

                    input.addEventListener('paste', function (e) {
                        e.preventDefault();
                        const pasteData = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '');
                        for (let i = 0; i < Math.min(pasteData.length, otpInputs.length); i++) {
                            otpInputs[i].value = pasteData[i];
                            otpInputs[i].dispatchEvent(new Event('input'));
                        }
                        const lastIndex = Math.min(pasteData.length, otpInputs.length) - 1;
                        otpInputs[lastIndex]?.focus();
                    });
                });
            }

            // -------------------------------
            // Password checklist
            // -------------------------------
            function initPasswordChecklist() {
                const input = document.getElementById('newpasswordInput');
                if (!input) return;

                const rules = {
                    lower: /[a-z]/,
                    upper: /[A-Z]/,
                    number: /\d/,
                    special: /[^A-Za-z0-9]/,
                    length: /.{8,}/
                };

                function toggle(id, ok) {
                    const el = document.getElementById(id);
                    if (!el) return;
                    el.classList.toggle('invalid', !ok);
                    el.classList.toggle('text-success', ok);
                    el.classList.toggle('text-danger', !ok);
                }

                const update = () => {
                    const v = input.value || '';
                    toggle('pass-lower', rules.lower.test(v));
                    toggle('pass-upper', rules.upper.test(v));
                    toggle('pass-number', rules.number.test(v));
                    toggle('pass-special', rules.special.test(v));
                    toggle('pass-length', rules.length.test(v));
                };

                input.addEventListener('input', update);
                update();
            }

            function initAll() {
                initOtpInputs();
                initPasswordChecklist();
            }

            // Init now + after navigate swaps content
            initAll();
            document.addEventListener('livewire:navigated', initAll);
        });
    </script>
    @endonce
    @endpush
</div>