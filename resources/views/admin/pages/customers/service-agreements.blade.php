<section class="card mb-3" id="customer-agreements" data-admin-context="{{ $this->customerIdentityLabel($focusedCustomer) }}">
    <div class="card-header"><h3 class="h6 text-muted">{{ __('admin_customer.commercial') }}</h3><h5 class="card-title mb-0">{{ __('agreement.title') }}</h5></div>
    <div class="card-body">
        <p>{{ __('admin_customer.agreement_help') }}</p>
        @if(!$this->agreementSchemaReady)
            <p class="alert alert-warning">{{ __('admin_cleanup.agreement_migration') }}</p>
        @else
            @if(\App\Support\Admin\AdminUiAccess::can('admin.finance'))
                <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#agreement-create">{{ __('agreement.record') }}</button>
                <div wire:ignore.self class="modal fade" id="agreement-create" tabindex="-1" aria-labelledby="agreement-create-title" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
                <div class="modal-header"><h5 id="agreement-create-title">{{ __('agreement.title') }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button></div>
                <div class="modal-body"><x-admin-validation-summary />
                <p class="small">{{ __('agreement.help') }}</p>
                @php
                    $agreementReviewPlans = $this->registerServicePlanOptions->mapWithKeys(fn ($plan) => [$plan->id => ['name' => $plan->name, 'app' => $plan->appMonthlyCredits(), 'api' => $plan->apiMonthlyCredits()]]);
                @endphp
                <form x-data="{ plans: @js($agreementReviewPlans) }" data-admin-method="recordServiceAgreement" data-admin-args="[]" data-admin-impact="{{ __('agreement.confirm') }}">
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label" for="agreement-plan">{{ __('admin_customer.base_plan') }}</label>
                            <select id="agreement-plan" class="form-select" wire:model.live="agreementPlanId" required data-admin-review>
                                <option value="">{{ __('Select plan') }}</option>
                                @foreach($this->registerServicePlanOptions as $plan)
                                    @if(!$plan->is_free)<option value="{{ $plan->id }}">{{ $plan->name }} · App {{ number_format($plan->appMonthlyCredits()) }} / API {{ number_format($plan->apiMonthlyCredits()) }}</option>@endif
                                @endforeach
                            </select>
                            @error('agreementPlanId')<div class="text-danger">{{ $message }}</div>@enderror
                        </div>
                        @foreach(['agreementStart' => 'start', 'agreementExpiry' => 'expiry'] as $field => $label)
                            <div class="col-md-4"><label class="form-label" for="{{ $field }}">{{ __('agreement.'.$label) }}</label><input id="{{ $field }}" type="date" class="form-control" wire:model="{{ $field }}" required data-admin-review dir="ltr">@error($field)<div class="text-danger">{{ $message }}</div>@enderror</div>
                        @endforeach
                        <div class="col-md-6"><label class="form-label" for="agreement-reference">{{ __('agreement.reference') }}</label><input id="agreement-reference" class="form-control" wire:model="agreementReference" maxlength="190" required data-admin-review dir="auto">@error('agreementReference')<div class="text-danger">{{ $message }}</div>@enderror</div>
                        <div class="col-md-6"><label class="form-label" for="agreement-amount">{{ __('agreement.amount') }}</label><input id="agreement-amount" type="number" min="0" max="1000000000000" step="1" class="form-control" wire:model="agreementAmount" data-admin-review dir="ltr">@error('agreementAmount')<div class="text-danger">{{ $message }}</div>@enderror</div>
                        @foreach (['agreementAppCredits' => 'app', 'agreementApiCredits' => 'api'] as $field => $label)
                            <div class="col-md-4"><label class="form-label" for="{{ $field }}">{{ __('admin_cleanup.'.$label.'_allowance') }}</label>
                                <input id="{{ $field }}" type="number" min="0" max="{{ config('service_agreements.max_monthly_credits') }}" class="form-control" wire:model="{{ $field }}" data-admin-review placeholder="{{ __('admin_cleanup.plan_default') }}">
                                @error($field)<div class="text-danger">{{ $message }}</div>@enderror
                            </div>
                        @endforeach
                        <div class="col-md-4"><label class="form-label" for="agreement-concurrency">{{ __('admin_p1.concurrency') }}</label>
                            <input id="agreement-concurrency" type="number" min="1" max="{{ config('service_agreements.max_concurrency') }}" class="form-control" wire:model="agreementConcurrency" data-admin-review placeholder="{{ config('service_agreements.default_concurrency') }}">
                            @error('agreementConcurrency')<div class="text-danger">{{ $message }}</div>@enderror
                        </div>
                        <p class="small text-muted">{{ __('admin_customer.agreement_permissions') }}</p><p class="small text-muted">{{ __('admin_cleanup.agreement_overrides', ['slots' => config('service_agreements.default_concurrency')]) }}</p>
                        <div class="col-12"><label class="form-label" for="agreement-reason">{{ __('Reason') }}</label><textarea id="agreement-reason" class="form-control" wire:model="agreementReason" minlength="10" maxlength="1000" required data-admin-review dir="auto"></textarea>@error('agreementReason')<div class="text-danger">{{ $message }}</div>@enderror</div>
                    </div>
                    <p class="small text-muted mt-3">{{ __('agreement.monthly_help') }}</p>
                    <div class="admin-customer-review my-3" aria-live="polite">
                        <h4 class="h6">{{ __('admin_customer.review') }}</h4>
                        <dl class="admin-customer-facts">
                            <dt>{{ __('admin_customer.base_plan') }}</dt><dd dir="auto" x-text="plans[$wire.agreementPlanId]?.name || '—'"></dd>
                            <dt>{{ __('agreement.start') }}</dt><dd><bdi x-text="$wire.agreementStart || '—'"></bdi></dd>
                            <dt>{{ __('agreement.expiry') }}</dt><dd><bdi x-text="$wire.agreementExpiry || '—'"></bdi></dd>
                            <dt>{{ __('admin_cleanup.app_allowance') }}</dt><dd x-text="$wire.agreementAppCredits === '' ? (plans[$wire.agreementPlanId]?.app ?? '—') : $wire.agreementAppCredits"></dd>
                            <dt>{{ __('admin_cleanup.api_allowance') }}</dt><dd x-text="$wire.agreementApiCredits === '' ? (plans[$wire.agreementPlanId]?.api ?? '—') : $wire.agreementApiCredits"></dd>
                            <dt>{{ __('admin_customer.concurrency') }}</dt><dd x-text="$wire.agreementConcurrency === '' ? {{ (int) config('service_agreements.default_concurrency') }} : $wire.agreementConcurrency"></dd>
                            <dt>{{ __('agreement.reference') }}</dt><dd dir="auto" x-text="$wire.agreementReference || '—'"></dd>
                            <dt>{{ __('agreement.amount') }}</dt><dd><bdi x-text="$wire.agreementAmount === '' ? '—' : $wire.agreementAmount"></bdi></dd>
                        </dl><p class="small mb-0">{{ __('admin_customer.agreement_permissions') }}</p>
                    </div>
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">{{ __('agreement.record') }}</button>
                </form>
                </div></div></div></div>
            @endif
            @foreach(['agreement','agreementPlanId','agreementStart','agreementExpiry','agreementAmount','agreementReference','agreementReason','agreementAppCredits','agreementApiCredits','agreementConcurrency','operation'] as $field)
                @error($field)<div class="text-danger" role="alert">{{ $message }}</div>@enderror
            @endforeach
        @endif
            <details class="admin-customer-evidence mt-3"><summary>{{ __('admin_customer.agreement_history') }}</summary><div class="table-responsive mt-3"><table class="table align-middle"><thead><tr>
                <th>{{ __('Service Plan') }}</th><th>{{ __('agreement.dates') }}</th><th>{{ __('Status') }}</th><th>{{ __('agreement.reference') }}</th><th>{{ __('agreement.details') }}</th>
            </tr></thead><tbody>
                @forelse($this->serviceAgreements as $agreement)
                    <tr wire:key="agreement-{{ $agreement->id }}">
                        <td dir="auto">{{ $agreement->servicePlan?->name }}<div class="small">{{ __('agreement.monthly_credits', ['app' => number_format($agreement->app_monthly_credits), 'api' => number_format($agreement->api_monthly_credits)]) }}</div><div class="small">{{ __('admin_p1.concurrency') }}: {{ $agreement->concurrent_jobs_limit ?? __('admin_cleanup.plan_default') }}</div></td>
                        <td dir="ltr">{{ $agreement->starts_at->format('Y-m-d') }} – {{ $agreement->ends_at->copy()->subDay()->format('Y-m-d') }}</td>
                        <td>{{ __('agreement.'.$agreement->status) }}
                            @if($agreement->status === 'requires_review')<p class="small">{{ __('agreement.review_help') }}</p>
                                @if($this->agreementSchemaReady && \App\Support\Admin\AdminUiAccess::can('admin.finance'))<button class="btn btn-sm btn-outline-warning" data-admin-reason-field="agreementReason" data-admin-method="retryServiceAgreement" data-admin-args="{{ json_encode([$agreement->id]) }}" data-admin-impact="{{ __('agreement.confirm') }}" wire:loading.attr="disabled">{{ __('agreement.retry') }}</button>@endif
                            @endif
                        </td>
                        <td dir="auto">{{ \App\Support\Admin\AdminData::redact($agreement->reference) }}
                            @if ($this->agreementSchemaReady && $agreement->ends_at->isFuture() && in_array($agreement->status, ['active', 'scheduled', 'requires_review']) && \App\Support\Admin\AdminUiAccess::can('admin.finance'))
                                <button type="button" class="btn btn-sm btn-outline-primary" wire:click="openAgreementAdjustment({{ $agreement->id }})">{{ __('admin_cleanup.adjust_agreement') }}</button>
                            @endif
                        </td>
                        <td><span dir="ltr">#{{ $agreement->id }} · {{ __('agreement.approved_by', ['id' => $agreement->admin_id]) }}</span>
                            @if(\App\Support\Admin\AdminUiAccess::can('admin.finance') && $agreement->agreed_amount_iqd !== null)<div>{{ number_format($agreement->agreed_amount_iqd) }} IQD</div>@endif
                            <div class="small" dir="auto">{{ \App\Support\Admin\AdminData::redact($agreement->reason) }}</div>
                        </td>
                    </tr>
                @empty<tr><td colspan="5">{{ __('agreement.empty') }}</td></tr>@endforelse
            </tbody></table></div></details>
    </div>
    @if ($this->agreementSchemaReady)
    <div wire:ignore.self class="modal fade" id="agreement-adjust" tabindex="-1" aria-labelledby="agreement-adjust-title" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
            <div class="modal-header"><h5 id="agreement-adjust-title">{{ __('admin_cleanup.adjust_agreement') }} #{{ $adjustingAgreementId }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button></div>
            <div class="modal-body"><x-admin-validation-summary />
                <p>{{ __('admin_cleanup.adjust_help') }}</p>
                <form data-admin-method="adjustServiceAgreement" data-admin-args="[]" data-admin-impact="{{ __('admin_cleanup.adjust_help') }}">
                    @foreach (['adjustAgreementApp' => 'admin_cleanup.app_allowance', 'adjustAgreementApi' => 'admin_cleanup.api_allowance', 'adjustAgreementConcurrency' => 'admin_p1.concurrency'] as $field => $label)
                        <div class="mb-3"><label for="{{ $field }}" class="form-label">{{ __($label) }}</label>
                            <input id="{{ $field }}" type="number" min="{{ $field === 'adjustAgreementConcurrency' ? 1 : 0 }}" max="{{ config($field === 'adjustAgreementConcurrency' ? 'service_agreements.max_concurrency' : 'service_agreements.max_monthly_credits') }}" class="form-control" wire:model="{{ $field }}" required data-admin-review>
                            @error($field)<div class="text-danger">{{ $message }}</div>@enderror
                        </div>
                    @endforeach
                    <label class="form-label" for="adjustAgreementReason">{{ __('Reason') }}</label>
                    <textarea id="adjustAgreementReason" class="form-control mb-3" wire:model="adjustAgreementReason" minlength="10" maxlength="1000" required data-admin-review dir="auto"></textarea>
                    @error('adjustAgreementReason')<div class="text-danger">{{ $message }}</div>@enderror
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">{{ __('Save Changes') }}</button>
                </form>
            </div>
        </div></div>
    </div>
    @endif
</section>
