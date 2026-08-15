<?php

namespace App\Support;

/** Maps canonical MlJob states to the shared V1/V2 visual semantics. */
class MetKurdV2JobStatusPresentation
{
    /** @return array{status:string,label:string,semantic:string,glass_class:string,is_active:bool} */
    public function for(?string $status): array
    {
        $status = strtolower(trim((string) $status));
        $definition = config("metkurd_v2.job_statuses.{$status}")
            ?? config('metkurd_v2.job_statuses.idle');

        return [
            'status' => $status !== '' ? $status : 'idle',
            'label' => __((string) data_get($definition, 'label', 'Idle')),
            'semantic' => (string) data_get($definition, 'semantic', 'secondary'),
            'glass_class' => (string) data_get($definition, 'glass_class', 'glass-load--secondary'),
            'is_active' => in_array($status, ['queued', 'submitting', 'running', 'saving'], true),
        ];
    }
}
