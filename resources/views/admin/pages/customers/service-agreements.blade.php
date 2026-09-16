<section class="card" data-admin-context="{{ $this->customerIdentityLabel($focusedCustomer) }}">
    <div class="card-header"><h5 class="card-title mb-0">{{ __('agreement.title') }}</h5></div>
    <div class="card-body">
        <p>{{ __('agreement.help') }}</p>
        @if(!$this->agreementSchemaReady)
            <p class="alert alert-warning">{{ __('agreement.migration') }}</p>
        @else
            @if(\App\Support\Admin\AdminUiAccess::can('admin.finance'))
                <form data-admin-method="recordServiceAgreement" data-admin-args="[]" data-admin-impact="{{ __('agreement.confirm') }}">
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label" for="agreement-plan">{{ __('Service Plan') }}</label>
                            <select id="agreement-plan" class="form-select" wire:model="agreementPlanId" required data-admin-review>
                                <option value="">{{ __('Select plan') }}</option>
                                @foreach($this->registerServicePlanOptions as $plan)
                                    @if(!$plan->is_free)<option value="{{ $plan->id }}">{{ $plan->name }} · App {{ number_format($plan->appMonthlyCredits()) }} / API {{ number_format($plan->apiMonthlyCredits()) }}</option>@endif
                                @endforeach
                            </select>
                        </div>
                        @foreach(['agreementStart' => 'start', 'agreementExpiry' => 'expiry'] as $field => $label)
                            <div class="col-md-4"><label class="form-label" for="{{ $field }}">{{ __('agreement.'.$label) }}</label><input id="{{ $field }}" type="date" class="form-control" wire:model="{{ $field }}" required data-admin-review dir="ltr"></div>
                        @endforeach
                        <div class="col-md-6"><label class="form-label" for="agreement-reference">{{ __('agreement.reference') }}</label><input id="agreement-reference" class="form-control" wire:model="agreementReference" maxlength="190" required data-admin-review dir="auto"></div>
                        <div class="col-md-6"><label class="form-label" for="agreement-amount">{{ __('agreement.amount') }}</label><input id="agreement-amount" type="number" min="0" max="1000000000000" step="1" class="form-control" wire:model="agreementAmount" data-admin-review dir="ltr"></div>
                        <div class="col-12"><label class="form-label" for="agreement-reason">{{ __('Reason') }}</label><textarea id="agreement-reason" class="form-control" wire:model="agreementReason" minlength="10" maxlength="1000" required data-admin-review dir="auto"></textarea></div>
                    </div>
                    <p class="small text-muted mt-3">{{ __('agreement.monthly_help') }}</p>
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">{{ __('agreement.record') }}</button>
                </form>
            @endif
            @foreach(['agreement','agreementPlanId','agreementStart','agreementExpiry','agreementAmount','agreementReference','agreementReason','operation'] as $field)
                @error($field)<div class="text-danger" role="alert">{{ $message }}</div>@enderror
            @endforeach
            <div class="table-responsive mt-3"><table class="table align-middle"><thead><tr>
                <th>{{ __('Service Plan') }}</th><th>{{ __('agreement.dates') }}</th><th>{{ __('Status') }}</th><th>{{ __('agreement.reference') }}</th><th>{{ __('agreement.details') }}</th>
            </tr></thead><tbody>
                @forelse($this->serviceAgreements as $agreement)
                    <tr wire:key="agreement-{{ $agreement->id }}">
                        <td dir="auto">{{ $agreement->servicePlan?->name }}<div class="small">{{ __('agreement.monthly_credits', ['app' => number_format($agreement->app_monthly_credits), 'api' => number_format($agreement->api_monthly_credits)]) }}</div></td>
                        <td dir="ltr">{{ $agreement->starts_at->format('Y-m-d') }} – {{ $agreement->ends_at->copy()->subDay()->format('Y-m-d') }}</td>
                        <td>{{ __('agreement.'.$agreement->status) }}
                            @if($agreement->status === 'requires_review')<p class="small">{{ __('agreement.review_help') }}</p>
                                @if(\App\Support\Admin\AdminUiAccess::can('admin.finance'))<button class="btn btn-sm btn-outline-warning" data-admin-method="retryServiceAgreement" data-admin-args="{{ json_encode([$agreement->id]) }}" data-admin-impact="{{ __('agreement.confirm') }}" wire:loading.attr="disabled">{{ __('agreement.retry') }}</button>@endif
                            @endif
                        </td>
                        <td dir="auto">{{ \App\Support\Admin\AdminData::redact($agreement->reference) }}</td>
                        <td><span dir="ltr">#{{ $agreement->id }} · {{ __('agreement.approved_by', ['id' => $agreement->admin_id]) }}</span>
                            @if(\App\Support\Admin\AdminUiAccess::can('admin.finance') && $agreement->agreed_amount_iqd !== null)<div>{{ number_format($agreement->agreed_amount_iqd) }} IQD</div>@endif
                            <div class="small" dir="auto">{{ \App\Support\Admin\AdminData::redact($agreement->reason) }}</div>
                        </td>
                    </tr>
                @empty<tr><td colspan="5">{{ __('agreement.empty') }}</td></tr>@endforelse
            </tbody></table></div>
        @endif
    </div>
</section>
