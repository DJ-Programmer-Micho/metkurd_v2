<?php

namespace App\Support\Admin;

use App\Models\PlanVoiceAccess;
use App\Models\ServicePlan;
use App\Models\Voice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

trait ManagesServiceVoicesPage
{
    #[Url(as: 'q', keep: true)]
    public string $search = '';

    #[Url(as: 'plan', keep: true)]
    public string $planFilter = 'all';

    #[Url(as: 'status', keep: true)]
    public string $statusFilter = 'all';

    #[Url(as: 'visibility', keep: true)]
    public string $visibilityFilter = 'all';

    #[Url(as: 'assignment', keep: true)]
    public string $assignmentFilter = 'all';

    public int $perPage = 10;

    public array $expandedVoices = [];

    public ?int $editingVoiceId = null;
    public string $voiceCode = '';
    public string $voiceName = '';
    public string $voiceEngine = '';
    public string $voiceGender = '';
    public string $voiceNotes = '';
    public int $voiceSortOrder = 0;
    public string $voiceVisibility = 'public';
    public string $voiceStatus = 'active';
    public string $voiceMetaJson = '';

    public ?int $editingAccessId = null;
    public ?int $accessPlanId = null;
    public ?int $accessVoiceId = null;
    public string $accessVisibility = 'public';
    public string $accessStatus = 'active';
    public int $accessSortOrder = 0;
    public string $accessNotes = '';
    public string $accessMetaJson = '';

    public ?int $voiceIdPendingDelete = null;
    public ?int $accessIdPendingDelete = null;
    public string $deleteTarget = '';
    public string $deleteLabel = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPlanFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedVisibilityFilter(): void
    {
        $this->resetPage();
    }

    public function updatedAssignmentFilter(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->planFilter = 'all';
        $this->statusFilter = 'all';
        $this->visibilityFilter = 'all';
        $this->assignmentFilter = 'all';
        $this->resetPage();
    }

    #[Computed]
    public function planOptions()
    {
        return ServicePlan::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function voiceOptions()
    {
        return Voice::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function topStats(): array
    {
        return [
            'voices' => (int) Voice::query()->count(),
            'active_voices' => (int) Voice::query()->where('is_active', true)->count(),
            'public_voices' => (int) Voice::query()->where('is_public', true)->count(),
            'access_rows' => (int) PlanVoiceAccess::query()->count(),
        ];
    }

    protected function voicesBaseQuery(): Builder
    {
        $accessStats = PlanVoiceAccess::query()
            ->groupBy('voice_id')
            ->selectRaw('voice_id')
            ->selectRaw('COUNT(*) as access_count')
            ->selectRaw('SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_access_count');

        $query = Voice::query()
            ->leftJoinSub($accessStats, 'access_stats', fn ($join) => $join->on('access_stats.voice_id', '=', 'voices.id'))
            ->select('voices.*')
            ->selectRaw('COALESCE(access_stats.access_count, 0) as access_count')
            ->selectRaw('COALESCE(access_stats.active_access_count, 0) as active_access_count')
            ->with([
                'planAccesses' => fn ($accessQuery) => $accessQuery
                    ->with('servicePlan:id,code,name')
                    ->orderBy('sort_order')
                    ->orderByDesc('is_active'),
            ]);

        if ($this->statusFilter === 'active') {
            $query->where('voices.is_active', true);
        } elseif ($this->statusFilter === 'maintenance') {
            $query->where('voices.is_active', false);
        }

        if ($this->visibilityFilter === 'public') {
            $query->where('voices.is_public', true);
        } elseif ($this->visibilityFilter === 'private') {
            $query->where('voices.is_public', false);
        }

        if ($this->assignmentFilter === 'assigned') {
            $query->whereRaw('COALESCE(access_stats.access_count, 0) > 0');
        } elseif ($this->assignmentFilter === 'unassigned') {
            $query->whereRaw('COALESCE(access_stats.access_count, 0) = 0');
        }

        if ($this->planFilter !== 'all') {
            $query->whereHas('planAccesses', function (Builder $accessQuery) {
                $accessQuery->where('service_plan_id', (int) $this->planFilter);
            });
        }

        $search = trim($this->search);

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search) {
                $builder
                    ->where('voices.code', 'like', "%{$search}%")
                    ->orWhere('voices.name', 'like', "%{$search}%")
                    ->orWhere('voices.meta->engine', 'like', "%{$search}%")
                    ->orWhere('voices.meta->gender', 'like', "%{$search}%");
            });
        }

        return $query
            ->orderBy('voices.sort_order')
            ->orderBy('voices.name');
    }

    #[Computed]
    public function voices()
    {
        return $this->voicesBaseQuery()->paginate($this->perPage);
    }

    public function toggleExpandedVoice(int $voiceId): void
    {
        if (in_array($voiceId, $this->expandedVoices, true)) {
            $this->expandedVoices = array_values(array_filter($this->expandedVoices, fn ($id) => (int) $id !== $voiceId));

            return;
        }

        $this->expandedVoices[] = $voiceId;
    }

    public function openVoiceCreateModal(): void
    {
        $this->resetVoiceForm();
        $this->dispatch('services-voices:modal-show', id: 'serviceVoiceModal');
    }

    public function openVoiceEditModal(int $voiceId): void
    {
        $voice = Voice::query()->findOrFail($voiceId);
        $meta = is_array($voice->meta) ? $voice->meta : [];

        $this->resetValidation();
        $this->editingVoiceId = $voice->id;
        $this->voiceCode = $voice->code;
        $this->voiceName = $voice->name;
        $this->voiceEngine = (string) data_get($meta, 'engine', '');
        $this->voiceGender = (string) data_get($meta, 'gender', '');
        $this->voiceNotes = (string) data_get($meta, 'notes', '');
        $this->voiceSortOrder = (int) $voice->sort_order;
        $this->voiceVisibility = $voice->is_public ? 'public' : 'private';
        $this->voiceStatus = $voice->is_active ? 'active' : 'maintenance';
        unset($meta['engine'], $meta['gender'], $meta['notes']);
        $this->voiceMetaJson = $meta ? (string) json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '';

        $this->dispatch('services-voices:modal-show', id: 'serviceVoiceModal');
    }

    public function saveVoice(): void
    {
        $rules = [
            'voiceName' => ['required', 'string', 'min:2', 'max:120'],
            'voiceEngine' => ['nullable', 'string', 'max:80'],
            'voiceGender' => ['nullable', 'string', 'max:80'],
            'voiceNotes' => ['nullable', 'string', 'max:500'],
            'voiceSortOrder' => ['required', 'integer', 'min:0'],
            'voiceVisibility' => ['required', Rule::in(['public', 'private'])],
            'voiceStatus' => ['required', Rule::in(['active', 'maintenance'])],
            'voiceMetaJson' => ['nullable', 'string'],
        ];

        if (!$this->editingVoiceId) {
            $rules['voiceCode'] = ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/', Rule::unique('voices', 'code')];
        }

        $this->validate($rules);

        $voice = $this->editingVoiceId
            ? Voice::query()->findOrFail($this->editingVoiceId)
            : new Voice();

        $meta = $this->decodeJsonField($this->voiceMetaJson, 'voiceMetaJson');
        $meta['engine'] = $this->emptyToNull($this->voiceEngine);
        $meta['gender'] = $this->emptyToNull($this->voiceGender);
        $meta['notes'] = $this->emptyToNull($this->voiceNotes);
        $meta = $this->cleanArray($meta);

        $voice->fill([
            'code' => $this->editingVoiceId ? $voice->code : trim($this->voiceCode),
            'name' => trim($this->voiceName),
            'is_public' => $this->voiceVisibility === 'public',
            'is_active' => $this->voiceStatus === 'active',
            'sort_order' => $this->voiceSortOrder,
            'meta' => $meta ?: null,
        ]);

        $voice->save();

        $this->dispatch('alert', type: 'success', message: $this->editingVoiceId ? 'Voice updated successfully.' : 'Voice created successfully.');
        $this->dispatch('services-voices:modal-hide', id: 'serviceVoiceModal');
        $this->resetVoiceForm();
    }

    public function toggleVoiceStatus(int $voiceId): void
    {
        $voice = Voice::query()->findOrFail($voiceId);
        $voice->update(['is_active' => !$voice->is_active]);
        $this->dispatch('alert', type: 'success', message: $voice->is_active ? 'Voice activated.' : 'Voice moved to maintenance.');
    }

    public function openAccessCreateModal(?int $voiceId = null): void
    {
        $this->resetAccessForm();
        $this->accessVoiceId = $voiceId;
        $this->dispatch('services-voices:modal-show', id: 'serviceVoiceAccessModal');
    }

    public function openAccessEditModal(int $accessId): void
    {
        $access = PlanVoiceAccess::query()->findOrFail($accessId);
        $meta = is_array($access->meta) ? $access->meta : [];

        $this->resetValidation();
        $this->editingAccessId = $access->id;
        $this->accessPlanId = (int) $access->service_plan_id;
        $this->accessVoiceId = (int) $access->voice_id;
        $this->accessVisibility = $access->is_public ? 'public' : 'private';
        $this->accessStatus = $access->is_active ? 'active' : 'maintenance';
        $this->accessSortOrder = (int) $access->sort_order;
        $this->accessNotes = (string) data_get($meta, 'notes', '');
        unset($meta['notes']);
        $this->accessMetaJson = $meta ? (string) json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '';

        $this->dispatch('services-voices:modal-show', id: 'serviceVoiceAccessModal');
    }

    public function saveAccess(): void
    {
        $this->validate([
            'accessPlanId' => ['required', 'integer', Rule::exists('service_plans', 'id')],
            'accessVoiceId' => ['required', 'integer', Rule::exists('voices', 'id')],
            'accessVisibility' => ['required', Rule::in(['public', 'private'])],
            'accessStatus' => ['required', Rule::in(['active', 'maintenance'])],
            'accessSortOrder' => ['required', 'integer', 'min:0'],
            'accessNotes' => ['nullable', 'string', 'max:500'],
            'accessMetaJson' => ['nullable', 'string'],
        ]);

        $duplicate = PlanVoiceAccess::query()
            ->where('service_plan_id', $this->accessPlanId)
            ->where('voice_id', $this->accessVoiceId)
            ->when($this->editingAccessId, fn ($query) => $query->whereKeyNot($this->editingAccessId))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'accessPlanId' => 'This plan already has an access row for the selected voice.',
            ]);
        }

        $meta = $this->decodeJsonField($this->accessMetaJson, 'accessMetaJson');
        $meta['notes'] = $this->emptyToNull($this->accessNotes);
        $meta = $this->cleanArray($meta);

        $access = $this->editingAccessId
            ? PlanVoiceAccess::query()->findOrFail($this->editingAccessId)
            : new PlanVoiceAccess();

        $access->fill([
            'service_plan_id' => $this->accessPlanId,
            'voice_id' => $this->accessVoiceId,
            'is_public' => $this->accessVisibility === 'public',
            'is_active' => $this->accessStatus === 'active',
            'sort_order' => $this->accessSortOrder,
            'meta' => $meta ?: null,
        ]);

        $access->save();

        $this->dispatch('alert', type: 'success', message: $this->editingAccessId ? 'Voice access updated successfully.' : 'Voice access created successfully.');
        $this->dispatch('services-voices:modal-hide', id: 'serviceVoiceAccessModal');
        $this->resetAccessForm();
    }

    public function toggleAccessStatus(int $accessId): void
    {
        $access = PlanVoiceAccess::query()->findOrFail($accessId);
        $access->update(['is_active' => !$access->is_active]);
        $this->dispatch('alert', type: 'success', message: $access->is_active ? 'Plan access activated.' : 'Plan access moved to maintenance.');
    }

    public function confirmVoiceDelete(int $voiceId): void
    {
        $voice = Voice::query()->findOrFail($voiceId);
        $this->voiceIdPendingDelete = $voice->id;
        $this->accessIdPendingDelete = null;
        $this->deleteTarget = 'voice';
        $this->deleteLabel = $voice->name;
        $this->dispatch('services-voices:modal-show', id: 'serviceVoiceDeleteModal');
    }

    public function confirmAccessDelete(int $accessId): void
    {
        $access = PlanVoiceAccess::query()->with(['servicePlan:id,name', 'voice:id,name'])->findOrFail($accessId);
        $this->accessIdPendingDelete = $access->id;
        $this->voiceIdPendingDelete = null;
        $this->deleteTarget = 'access';
        $this->deleteLabel = ($access->servicePlan?->name ?? 'Plan') . ' / ' . ($access->voice?->name ?? 'Voice');
        $this->dispatch('services-voices:modal-show', id: 'serviceVoiceDeleteModal');
    }

    public function performDelete(): void
    {
        if ($this->deleteTarget === 'voice' && $this->voiceIdPendingDelete) {
            Voice::query()->findOrFail($this->voiceIdPendingDelete)->delete();
            $this->dispatch('alert', type: 'success', message: 'Voice deleted successfully.');
        }

        if ($this->deleteTarget === 'access' && $this->accessIdPendingDelete) {
            PlanVoiceAccess::query()->findOrFail($this->accessIdPendingDelete)->delete();
            $this->dispatch('alert', type: 'success', message: 'Voice access deleted successfully.');
        }

        $this->dispatch('services-voices:modal-hide', id: 'serviceVoiceDeleteModal');
        $this->resetDeleteState();
    }

    public function resetVoiceForm(): void
    {
        $this->resetValidation();
        $this->editingVoiceId = null;
        $this->voiceCode = '';
        $this->voiceName = '';
        $this->voiceEngine = '';
        $this->voiceGender = '';
        $this->voiceNotes = '';
        $this->voiceSortOrder = 0;
        $this->voiceVisibility = 'public';
        $this->voiceStatus = 'active';
        $this->voiceMetaJson = '';
    }

    public function resetAccessForm(): void
    {
        $this->resetValidation();
        $this->editingAccessId = null;
        $this->accessPlanId = null;
        $this->accessVoiceId = null;
        $this->accessVisibility = 'public';
        $this->accessStatus = 'active';
        $this->accessSortOrder = 0;
        $this->accessNotes = '';
        $this->accessMetaJson = '';
    }

    public function resetDeleteState(): void
    {
        $this->voiceIdPendingDelete = null;
        $this->accessIdPendingDelete = null;
        $this->deleteTarget = '';
        $this->deleteLabel = '';
    }

    public function visibilityBadgeClasses(bool $isPublic): string
    {
        return $isPublic ? 'bg-info-subtle text-info' : 'bg-dark-subtle text-body';
    }

    public function statusBadgeClasses(bool $isActive): string
    {
        return $isActive ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning';
    }

    protected function decodeJsonField(?string $value, string $field): array
    {
        $raw = trim((string) $value);

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw ValidationException::withMessages([
                $field => 'Please enter a valid JSON object.',
            ]);
        }

        return $decoded;
    }

    protected function cleanArray(array $value): array
    {
        $clean = [];

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $item = $this->cleanArray($item);
            }

            if ($item === null || $item === '' || $item === []) {
                continue;
            }

            $clean[$key] = $item;
        }

        return $clean;
    }

    protected function emptyToNull(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
