<?php

namespace App\Support;

class PricingRuleEngine
{
    public static function matches(?array $conditions, string $planCode, array $context = []): bool
    {
        if (!$conditions) return true;

        // match by plan_codes
        if (!empty($conditions['plan_codes']) && is_array($conditions['plan_codes'])) {
            if (!in_array($planCode, $conditions['plan_codes'], true)) return false;
        }

        // add future matching logic here (duration, filetype, etc.) using $context
        return true;
    }

    public static function resolveCost(string $ruleType, ?int $costCredits, ?array $config = null): int
    {
        if ($ruleType === 'free') return 0;
        if ($ruleType === 'fixed' || $ruleType === 'conditional') return (int) ($costCredits ?? 0);

        // tiered/formula can be implemented later using $config
        return (int) ($costCredits ?? 0);
    }
}