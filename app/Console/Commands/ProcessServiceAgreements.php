<?php

namespace App\Console\Commands;

use App\Models\ServicePlanAgreement;
use App\Services\Billing\ServiceAgreementLifecycle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ProcessServiceAgreements extends Command
{
    protected $signature = 'billing:process-service-agreements {--customer=} {--dry-run}';

    protected $description = 'Activate dated external agreements, reset monthly access credits and expire terms without provider calls.';

    public function handle(ServiceAgreementLifecycle $lifecycle): int
    {
        if (! Schema::hasTable('service_plan_agreements')) {
            $this->warn('Service agreement migration has not been applied. No changes made.');

            return self::SUCCESS;
        }
        $query = ServicePlanAgreement::where(fn ($q) => $q->whereIn('status', ['scheduled', 'active'])
            ->orWhere(fn ($expired) => $expired->where('status', 'requires_review')->where('ends_at', '<=', now())))
            ->where('starts_at', '<=', now());
        if ($this->option('customer')) {
            $query->where('customer_id', (int) $this->option('customer'));
        }
        $failed = false;
        foreach ($query->orderBy('id')->lazyById(100) as $agreement) {
            if ($this->option('dry-run')) {
                $this->line('Due agreement #'.$agreement->id.' (no changes)');

                continue;
            }
            try {
                $this->line('Agreement #'.$agreement->id.': '.$lifecycle->process($agreement->id));
            } catch (\Throwable) {
                $failed = true;
                $this->error('Agreement #'.$agreement->id.' could not be processed; no partial allocation was committed.');
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
