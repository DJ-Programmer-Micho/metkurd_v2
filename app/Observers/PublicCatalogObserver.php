<?php

namespace App\Observers;

use App\Support\Landing\PublicProductCatalog;
use Illuminate\Database\Eloquent\Model;

class PublicCatalogObserver
{
    public function saved(Model $model): void
    {
        $this->invalidate($model);
    }

    public function deleted(Model $model): void
    {
        $this->invalidate($model);
    }

    private function invalidate(Model $model): void
    {
        app(PublicProductCatalog::class)->forget();
        if ($model->getConnection()->transactionLevel() > 0) {
            $model->getConnection()->afterCommit(fn () => app(PublicProductCatalog::class)->forget());
        }
    }
}
