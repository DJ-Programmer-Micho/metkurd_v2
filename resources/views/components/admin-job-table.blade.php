@props(['rows'])
<div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead><tr>
            @foreach (['service', 'customer', 'local_status', 'created', 'channel', 'persistence', 'financial', 'detail'] as $label)
                <th>{{ __('admin_ux.'.$label) }}</th>
            @endforeach
        </tr></thead>
        <tbody>
        @forelse ($rows as $row)
            <tr wire:key="job-summary-{{ $row['id'] }}">
                <td dir="auto"><strong>{{ $row['model'] ?: $row['tool_action'] }}</strong>
                    @if($row['attention'])<span class="badge bg-warning-subtle text-warning d-block mt-1">{{ __('admin_ux.attention') }}</span>@endif
                </td>
                <td><a wire:navigate dir="auto" href="{{ route('admin.customers.detail', ['locale' => app()->getLocale(), 'customer' => $row['customer_id']]) }}">{{ $row['customer'] ?: $row['customer_id'] }}</a></td>
                <td><span class="badge bg-{{ in_array($row['local_lifecycle'], ['failed','delete_failed']) ? 'danger' : ($row['local_lifecycle'] === 'done' ? 'success' : 'secondary') }}-subtle text-body">{{ \Illuminate\Support\Facades\Lang::has('admin_p2.'.$row['local_lifecycle']) ? __('admin_p2.'.$row['local_lifecycle']) : $row['local_lifecycle'] }}</span></td>
                <td><time dir="ltr">{{ $row['created_at'] }}</time><small class="d-block text-muted">{{ $row['age'] }}</small></td>
                <td><bdi dir="ltr">{{ strtoupper($row['channel']) }}</bdi></td>
                <td>{{ __('admin_p2.'.$row['persisted_result']) }}</td>
                <td>
                    @if($row['channel'] === 'api')
                        <span>{{ __('admin_ux.api_reservation') }}:</span>
                        {{ __('admin_p2.'.data_get($row, 'api_reservation.status', 'not_recorded')) }}
                    @elseif($row['failure_stage'] === 'refund_pending')
                        <span class="text-warning">{{ __('admin_p2.refund_pending') }}</span>
                    @elseif($row['refunded_at'])
                        {{ __('admin_ux.refunded') }} · {{ number_format($row['refund_amount'] ?? 0) }}
                    @else
                        {{ __('admin_ux.app_charge') }} · {{ number_format($row['charged'] ?? 0) }}
                    @endif
                </td>
                <td><details><summary>{{ __('admin_ux.detail') }}</summary>
                    <div class="admin-job-details p-3"><x-admin-operation-status :row="$row" />
                        <p class="small text-muted">{{ __('admin_ux.evidence_help') }}</p>
                        <x-admin-operation-values :values="$row" />
                        <a wire:navigate class="btn btn-sm btn-soft-info" href="{{ route('admin.operations', ['locale' => app()->getLocale(), 'job' => $row['id'], 'section' => 'files', 'customerFilter' => $row['customer_id']]) }}">{{ __('admin_p2.job_trace') }}</a>
                    </div>
                </details></td>
            </tr>
        @empty
            <tr><td colspan="8" class="text-center p-4 text-muted">{{ __('admin_p2.empty') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
