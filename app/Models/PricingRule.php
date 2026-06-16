<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PricingRule extends Model
{
    use HasFactory;

    public const CHANNEL_APP = 'app';

    public const CHANNEL_API = 'api';

    public const CHANNEL_MOBILE = 'mobile';

    public const CHANNEL_ALL = 'all';

    public const CHANNELS = [
        self::CHANNEL_APP,
        self::CHANNEL_API,
        self::CHANNEL_MOBILE,
        self::CHANNEL_ALL,
    ];

    protected $table = 'pricing_rules';

    protected $fillable = [
        'tool_action_id',
        'service_plan_id',
        'pricing_channel',
        'rule_scope',
        'rule_type',
        'priority',
        'metric_code',
        'unit_size',
        'credits_per_unit',
        'rounding_mode',
        'rounding_step',
        'minimum_credits',
        'conditions',
        'config',
        'is_active',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'priority' => 'integer',
        'unit_size' => 'decimal:4',
        'credits_per_unit' => 'decimal:4',
        'rounding_step' => 'decimal:4',
        'minimum_credits' => 'integer',
        'conditions' => 'array',
        'config' => 'array',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function toolAction(): BelongsTo
    {
        return $this->belongsTo(ToolAction::class, 'tool_action_id');
    }

    public function servicePlan(): BelongsTo
    {
        return $this->belongsTo(ServicePlan::class, 'service_plan_id');
    }

    public static function channels(): array
    {
        return self::CHANNELS;
    }

    /**
     * @return array<int, string>
     */
    public static function primaryChannels(): array
    {
        return [
            self::CHANNEL_APP,
            self::CHANNEL_MOBILE,
            self::CHANNEL_API,
        ];
    }

    public static function normalizeChannel(?string $channel, string $default = self::CHANNEL_APP): string
    {
        $channel = strtolower(trim((string) $channel));

        return in_array($channel, self::channels(), true) ? $channel : $default;
    }

    /**
     * @return array<int, string>
     */
    public static function fallbackChannels(?string $channel): array
    {
        return match (self::normalizeChannel($channel)) {
            self::CHANNEL_API => [self::CHANNEL_API, self::CHANNEL_ALL],
            self::CHANNEL_MOBILE => [self::CHANNEL_MOBILE, self::CHANNEL_ALL],
            self::CHANNEL_ALL => [self::CHANNEL_ALL],
            default => [self::CHANNEL_APP, self::CHANNEL_ALL],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function groupingAttributes(bool $includeChannel = false, bool $includeState = false): array
    {
        $attributes = [
            'tool_action_id' => (int) $this->tool_action_id,
            'service_plan_id' => $this->service_plan_id ? (int) $this->service_plan_id : null,
            'rule_type' => (string) $this->rule_type,
            'metric_code' => (string) $this->metric_code,
            'unit_size' => (string) $this->getRawOriginal('unit_size'),
            'rounding_mode' => (string) $this->rounding_mode,
            'rounding_step' => (string) $this->getRawOriginal('rounding_step'),
            'minimum_credits' => (int) $this->minimum_credits,
            'priority' => (int) $this->priority,
            'conditions' => $this->normalizedGroupingPayload($this->conditions),
            'config' => $this->normalizedGroupingPayload($this->config),
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
        ];

        if ($includeChannel) {
            $attributes['pricing_channel'] = self::normalizeChannel((string) $this->pricing_channel);
        }

        if ($includeState) {
            $attributes['is_active'] = (bool) $this->is_active;
        }

        return $attributes;
    }

    public function groupingSignature(bool $includeChannel = false, bool $includeState = false): string
    {
        return json_encode($this->groupingAttributes($includeChannel, $includeState), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    protected function normalizedGroupingPayload(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        ksort($payload);

        return array_map(function ($value) {
            if (! is_array($value)) {
                return $value;
            }

            ksort($value);

            return $value;
        }, $payload);
    }
}
