<?php

namespace App\Support\Admin;

use App\Models\ServicePlan;
use App\Services\Admin\AdminV2Catalog;
use App\Services\CustomerApi\CustomerApiAccessService;
use Livewire\Attributes\Computed;

trait ShowsV2Catalog
{
    public ?int $v2PlanId = null;

    public string $v2SampleJson = '{"chars":100,"seconds":60,"pages":1,"language":"ckb","model_variant":"fine_tuned"}';

    #[Computed]
    public function v2Preview(): array
    {
        $plan = $this->v2PlanId ? ServicePlan::find($this->v2PlanId) : ServicePlan::orderBy('sort_order')->first();
        if (! $plan) {
            return [];
        }
        $sample = json_decode($this->v2SampleJson, true);
        if (! is_array($sample) || strlen($this->v2SampleJson) > 4000) {
            return ['error' => true];
        }
        foreach (['chars', 'seconds', 'pages'] as $key) {
            if (isset($sample[$key]) && (! is_numeric($sample[$key]) || $sample[$key] < 1 || $sample[$key] > 1000000)) {
                return ['error' => true];
            }
        }

        return ['plan' => $plan, 'effective' => app(CustomerApiAccessService::class)->configForPlan($plan),
            'rows' => app(AdminV2Catalog::class)->rows($plan, $sample)];
    }

    #[Computed]
    public function v2Plans()
    {
        return ServicePlan::orderBy('sort_order')->get(['id', 'name']);
    }
}
