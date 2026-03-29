<?php

namespace App\Support;

use App\Models\Tool;
use App\Models\ToolAction;
use App\Models\Voice;
use Illuminate\Support\Facades\Cache;

class AppToolCatalog
{
    public function toolId(string $code): ?int
    {
        $code = strtolower(trim($code));

        if ($code === '') {
            return null;
        }

        return Cache::remember(
            'tool-id:' . $code,
            now()->addMinutes(15),
            fn () => Tool::query()->where('code', $code)->value('id')
        );
    }

    /**
     * @param  array<int, string>  $codes
     * @return array<int, int>
     */
    public function toolIds(array $codes): array
    {
        $ids = [];

        foreach ($codes as $code) {
            $id = $this->toolId((string) $code);

            if ($id) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<int, string>  $toolCodes
     * @return array<string, array<string, string>>
     */
    public function actionOptionMaps(array $toolCodes): array
    {
        $toolCodes = collect($toolCodes)
            ->map(fn ($code) => strtolower(trim((string) $code)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($toolCodes === []) {
            return [];
        }

        $cacheKey = 'tool-action-options:' . implode(',', $toolCodes);

        /** @var array<string, array<string, string>> $maps */
        $maps = Cache::remember($cacheKey, now()->addMinutes(15), function () use ($toolCodes) {
            $actions = ToolAction::query()
                ->whereIn('tool_code', $toolCodes)
                ->where('is_active', true)
                ->orderBy('id')
                ->get(['tool_code', 'action_code', 'name']);

            $maps = [];

            foreach ($toolCodes as $toolCode) {
                $maps[$toolCode] = [];
            }

            foreach ($actions as $action) {
                $toolCode = strtolower(trim((string) $action->tool_code));
                $actionCode = trim((string) $action->action_code);

                if ($toolCode === '' || $actionCode === '') {
                    continue;
                }

                $maps[$toolCode][$actionCode] = trim((string) $action->name);
            }

            return $maps;
        });

        return $maps;
    }

    /**
     * @return array<string, string>
     */
    public function voiceOptionsForPlan(int $planId): array
    {
        if ($planId <= 0) {
            return [];
        }

        /** @var array<string, string> $voices */
        $voices = Cache::remember(
            'plan-voice-options:' . $planId,
            now()->addMinutes(15),
            fn () => Voice::query()
                ->select('voices.code', 'voices.name')
                ->join('plan_voice_access as pva', 'pva.voice_id', '=', 'voices.id')
                ->where('voices.is_active', true)
                ->where('pva.is_active', true)
                ->where('pva.service_plan_id', $planId)
                ->orderBy('voices.sort_order')
                ->pluck('voices.name', 'voices.code')
                ->toArray()
        );

        return $voices;
    }
}
