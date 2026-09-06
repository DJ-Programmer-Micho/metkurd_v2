<?php

namespace App\Observers;

use App\Services\Admin\AdminAudit;
use Illuminate\Database\Eloquent\Model;

class AdminAuditObserver
{
    public function saving(Model $model): void
    {
        if (! auth('admin')->check()) {
            return;
        }
        foreach (['meta', 'settings', 'fee_config', 'metadata', 'conditions', 'config', 'limits'] as $field) {
            if (is_array($model->getAttribute($field))) {
                $original = json_decode((string) $model->getRawOriginal($field), true) ?? [];
                $model->setAttribute($field, \App\Support\Admin\AdminData::mergeEditable($original, $model->getAttribute($field)));
            }
        }
    }

    public function saved(Model $model): void
    {
        if (! auth('admin')->check()) {
            return;
        }
        app(AdminAudit::class)->record($model->wasRecentlyCreated ? 'created' : 'updated', $model::class, $model->getKey(),
            $model->wasRecentlyCreated ? [] : $model->getRawOriginal(), $model->getChanges(), $model->getAttributes());
    }

    public function deleted(Model $model): void
    {
        if (auth('admin')->check()) {
            app(AdminAudit::class)->record('deleted', $model::class, $model->getKey(), $model->getRawOriginal(), ['delete' => true]);
        }
    }
}
