<?php

namespace App\Services\Billing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Current reporting only. Historical orders and their revenue classification stay intact. */
class BillingReportingBoundary
{
    public const ACTION = 'billing.cutover_reset_payment_domain';

    public function current(): ?array
    {
        if (! Schema::hasTable('admin_audit_events')) {
            return null;
        }
        // Do not hydrate the large private cutover manifest on every reporting query.
        $row = DB::table('admin_audit_events')->where('action', self::ACTION)
            ->where('target_type', PaymentDomainCutover::class)->latest('id')->first(['after_state->reporting_boundary as boundary']);
        if ($row === null) {
            return null;
        }
        $boundary = json_decode((string) $row->boundary, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($boundary) || empty($boundary['starts_at']) || ! isset($boundary['credit_order_id'], $boundary['payment_id'])) {
            throw new \RuntimeException('The persisted billing reporting boundary is invalid.');
        }

        return $boundary;
    }

    public function apply($query, string $table = 'credit_orders')
    {
        if ($boundary = $this->current()) {
            // The ID watermark also excludes retained history with a future-dated timestamp.
            $query->where($table.'.created_at', '>=', $boundary['starts_at'])
                ->where($table.'.id', '>', $boundary[$table === 'payments' ? 'payment_id' : 'credit_order_id']);
        }

        return $query;
    }
}
