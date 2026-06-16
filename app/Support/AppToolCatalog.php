<?php

namespace App\Support;

use App\Models\Tool;
use App\Models\ToolAction;
use App\Models\Voice;
use Illuminate\Support\Facades\Cache;

class AppToolCatalog
{
    /**
     * @var array<string, int|null>
     */
    protected static array $toolIds = [];

    /**
     * @var array<string, array<string, array<string, string>>>
     */
    protected static array $actionMaps = [];

    /**
     * @var array<string, array<string, string>>
     */
    protected static array $voiceOptions = [];

    public function toolId(string $code): ?int
    {
        $code = strtolower(trim($code));

        if ($code === '') {
            return null;
        }

        if (array_key_exists($code, self::$toolIds)) {
            return self::$toolIds[$code];
        }

        return self::$toolIds[$code] = Cache::remember(
            'tool-id:'.$code,
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

        $cacheKey = 'tool-action-options:'.implode(',', $toolCodes);

        if (array_key_exists($cacheKey, self::$actionMaps)) {
            return self::$actionMaps[$cacheKey];
        }

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

        return self::$actionMaps[$cacheKey] = $maps;
    }

    /**
     * @return array<string, string>
     */
    public function voiceOptionsForPlan(int $planId): array
    {
        return $this->voiceOptionsForPlanAndEngine($planId);
    }

    /**
     * @return array<string, string>
     */
    public function voiceOptionsForPlanAndEngine(int $planId, ?string $engine = null): array
    {
        if ($planId <= 0) {
            return [];
        }

        $normalizedEngine = strtolower(trim((string) $engine));
        $cacheKey = $planId.'|'.($normalizedEngine !== '' ? $normalizedEngine : '*');

        if (array_key_exists($cacheKey, self::$voiceOptions)) {
            return self::$voiceOptions[$cacheKey];
        }

        /** @var array<string, string> $voices */
        $voices = Cache::remember(
            'plan-voice-options:'.$cacheKey,
            now()->addMinutes(15),
            function () use ($normalizedEngine, $planId): array {
                $query = Voice::query()
                    ->select('voices.code', 'voices.name')
                    ->join('plan_voice_access as pva', 'pva.voice_id', '=', 'voices.id')
                    ->where('voices.is_active', true)
                    ->where('pva.is_active', true)
                    ->where('pva.service_plan_id', $planId);

                if ($normalizedEngine !== '') {
                    $query->where('voices.meta->engine', $normalizedEngine);
                }

                return $query
                    ->orderBy('voices.sort_order')
                    ->pluck('voices.name', 'voices.code')
                    ->toArray();
            }
        );

        return self::$voiceOptions[$cacheKey] = $voices;
    }
}
