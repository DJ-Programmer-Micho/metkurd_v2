<?php

namespace App\Services\Admin;

use App\Models\AdminAuditEvent;
use App\Support\Admin\AdminData;

class AdminAudit
{
    public ?string $operationId = null;

    public ?string $reason = null;

    public function record(string $action, string $type, string|int|null $id, array $before = [], array $requested = [], array $after = []): void
    {
        AdminAuditEvent::create([
            'admin_id' => auth('admin')->id(), 'operation_id' => $this->operationId,
            'action' => $action, 'target_type' => $type, 'target_id' => $id,
            'reason' => AdminData::redact($this->reason),
            'before_state' => AdminData::redact($before), 'requested' => AdminData::redact($requested),
            'after_state' => AdminData::redact($after),
        ]);
    }
}
