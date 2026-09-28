<?php

namespace App\Support\Admin;

use App\Models\ToolAction;

/** Presentation of existing enforcement; never creates additional limit authorities. */
class AdminServiceLimits
{
    public function characterField(?int $actionId): bool
    {
        $action = ToolAction::find($actionId);

        $actions = collect(app(\App\Support\MetKurdV2ToolCatalog::class)->services())->pluck('tools')->flatMap(fn ($tools) => array_values($tools))
            ->whereIn('kind', ['omni_tts', 'omni_clone'])->pluck('legacy_action');

        return $action && $actions->contains($action->full_code);
    }

    public function reference(?int $actionId): array
    {
        $action = ToolAction::find($actionId);
        if (! $action) {
            return [];
        }
        if (in_array($action->tool_code, ['zeta', 'theta'], true)) {
            return [
                'admin_cleanup.segments' => config('metkurd_v2.multi_speaker.max_segments'),
                'admin_cleanup.segment_chars' => config('metkurd_v2.multi_speaker.max_segment_chars'),
                'admin_cleanup.total_chars' => config('metkurd_v2.multi_speaker.max_total_chars'),
            ];
        }
        if ($action->tool_code === 'harakat') {
            return ['admin_cleanup.characters' => app(\App\Services\MetKurd\V2\HarakatInput::class)->limit()];
        }
        if ($action->tool_code === 'ocr') {
            return ['admin_cleanup.pages' => \App\Services\OCR\OcrDocumentProbe::MAX_PAGES];
        }

        return [];
    }
}
