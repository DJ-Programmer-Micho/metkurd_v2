<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanEntitlement extends Model
{
    use HasFactory;

    public const CHANNEL_APP = 'app';

    public const CHANNEL_API = 'api';

    public const CHANNEL_MOBILE = 'mobile';

    public const CHANNEL_ALL = 'all';

    protected $fillable = ['service_plan_id', 'tool_action_id', 'entitlement_channel', 'allowed', 'limits'];

    protected $casts = ['allowed' => 'boolean', 'limits' => 'array'];

    public function servicePlan(): BelongsTo
    {
        return $this->belongsTo(ServicePlan::class, 'service_plan_id');
    }

    public function toolAction(): BelongsTo
    {
        return $this->belongsTo(ToolAction::class, 'tool_action_id');
    }

    public static function channels(bool $includeAll = false): array
    {
        $channels = [
            self::CHANNEL_APP,
            self::CHANNEL_API,
            self::CHANNEL_MOBILE,
        ];

        if ($includeAll) {
            $channels[] = self::CHANNEL_ALL;
        }

        return $channels;
    }

    public static function normalizeChannel(?string $channel, string $default = self::CHANNEL_APP, bool $includeAll = false): string
    {
        $channel = strtolower(trim((string) $channel));

        return in_array($channel, self::channels($includeAll), true) ? $channel : $default;
    }

    /**
     * @return array<int, string>
     */
    public static function fallbackChannels(?string $channel, bool $includeAll = false): array
    {
        return match (self::normalizeChannel($channel, self::CHANNEL_APP, $includeAll)) {
            self::CHANNEL_API => $includeAll
                ? [self::CHANNEL_API, self::CHANNEL_ALL]
                : [self::CHANNEL_API],
            self::CHANNEL_MOBILE => $includeAll
                ? [self::CHANNEL_MOBILE, self::CHANNEL_ALL]
                : [self::CHANNEL_MOBILE],
            self::CHANNEL_ALL => [self::CHANNEL_ALL],
            default => $includeAll
                ? [self::CHANNEL_APP, self::CHANNEL_ALL]
                : [self::CHANNEL_APP],
        };
    }
}
