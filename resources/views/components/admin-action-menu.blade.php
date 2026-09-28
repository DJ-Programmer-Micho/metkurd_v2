@props(['label'])
<div {{ $attributes->class(['dropdown']) }}>
    <button type="button" class="btn d-flex align-items-center gap-2" data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ $label }}">
        {{ $trigger ?? $label }}<i class="ri-arrow-down-s-line" aria-hidden="true"></i>
    </button>
    <div class="dropdown-menu dropdown-menu-end">{{ $slot }}</div>
</div>
