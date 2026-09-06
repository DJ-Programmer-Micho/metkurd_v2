<div class="mb-3">
    <label class="form-label" for="admin-change-reason">{{ __('admin_p0.change_reason') }}</label>
    <input id="admin-change-reason" class="form-control" wire:model="adminChangeReason" maxlength="500">
    @error('adminChangeReason') <div class="text-danger small">{{ $message }}</div> @enderror
    @error('delete') <div class="text-danger small">{{ $message }}</div> @enderror
</div>
