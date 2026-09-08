<div class="mb-3">
    <p class="small text-muted">{{ __('admin_p3.read_only') }}</p>
    <label class="form-label" for="admin-change-reason">{{ __('admin_p0.change_reason') }}</label>
    <input id="admin-change-reason" dir="auto" class="form-control" wire:model="adminChangeReason" maxlength="500">
    @error('adminChangeReason') <div class="text-danger small">{{ $message }}</div> @enderror
    @error('delete') <div class="text-danger small">{{ $message }}</div> @enderror
</div>
