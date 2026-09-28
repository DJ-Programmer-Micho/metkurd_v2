@props(['row', 'fields'])
<dl class="mb-0">
@foreach($fields as $field)
    @if(isset($row[$field]))
        <dt class="small text-muted">{{ \Illuminate\Support\Facades\Lang::has('admin_developer.'.$field) ? __('admin_developer.'.$field) : __('admin_p2.'.$field) }}</dt>
        <dd class="small mb-1" dir="auto">
            @if(is_array($row[$field]))
                @foreach($row[$field] as $scope)<bdi dir="ltr">{{ $scope }}</bdi>{{ !$loop->last ? ' · ' : '' }}@endforeach
            @else
                <bdi dir="ltr">{{ \Illuminate\Support\Facades\Lang::has('admin_p2.'.$row[$field]) ? __('admin_p2.'.$row[$field]) : $row[$field] }}</bdi>
            @endif
        </dd>
    @endif
@endforeach
</dl>
