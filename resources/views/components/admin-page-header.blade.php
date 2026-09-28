@props(['title', 'description' => null])
<div {{ $attributes->class(['admin-page-header']) }}>
    <div><h1>{{ $title }}</h1>@if($description)<p>{{ $description }}</p>@endif</div>
    @if($slot->isNotEmpty())<div class="d-flex gap-2 flex-wrap">{{ $slot }}</div>@endif
</div>
