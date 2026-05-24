@php
    $groupRows = isset($groups) && is_array($groups) ? array_values($groups) : [];
@endphp

@if($groupRows !== [])
    <div class="demo-sample-board demo-sample-board--ctts-groups">
        @foreach($groupRows as $groupIndex => $group)
            @php
                $groupExamples = is_array(data_get($group, 'items')) ? array_values((array) data_get($group, 'items')) : [];
                $groupKey = trim((string) data_get($group, 'key', 'group-' . $groupIndex));
                $groupLabel = trim((string) data_get($group, 'label', __('Original vs Cloned')));
                $groupDescription = trim((string) data_get($group, 'description', ''));
            @endphp
            <div class="demo-sample-card demo-sample-card--group">
                <header class="demo-card-head">
                    <div class="demo-group-head-copy">
                        <h3>{{ $groupLabel }}</h3>
                        @if($groupDescription !== '')
                            <p class="demo-group-description">{{ $groupDescription }}</p>
                        @endif
                    </div>
                    <span class="demo-chip">{{ count($groupExamples) }} {{ __('samples') }}</span>
                </header>

                <div class="demo-compare-list">
                    @forelse($groupExamples as $exampleIndex => $example)
                        @include('landing.components.tool-demos._ctts-row', [
                            'example' => $example,
                            'uidPrefix' => 'ctts-' . $groupKey . '-' . $exampleIndex,
                        ])
                    @empty
                        <div class="demo-empty">{{ __('No samples configured yet.') }}</div>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>
@else
    <div class="demo-sample-board">
        <div class="demo-sample-card">
            <header class="demo-card-head">
                <h3>{{ __('Original vs Cloned') }}</h3>
            </header>

            <div class="demo-compare-list">
                @forelse($items as $example)
                    @include('landing.components.tool-demos._ctts-row', [
                        'example' => $example,
                        'uidPrefix' => 'ctts-' . $loop->index,
                    ])
                @empty
                    <div class="demo-empty">{{ __('No clone comparison examples configured yet.') }}</div>
                @endforelse
            </div>
        </div>
    </div>
@endif
