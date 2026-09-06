<?php

namespace App\Support\Admin;

trait SecureAdminComponent
{
    public string $adminChangeReason = '';

    protected function authorizeAdminChange(string $capability): void
    {
        AdminAccess::authorize($capability);
        $this->validate(['adminChangeReason' => 'required|string|min:10|max:500']);
        $audit = app(\App\Services\Admin\AdminAudit::class);
        $audit->reason = trim($this->adminChangeReason);
        $audit->operationId = (string) \Illuminate\Support\Str::uuid();
    }

    public function bootSecureAdminComponent(): void
    {
        AdminAccess::authorize('admin.read');
    }
}
