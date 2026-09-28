@props(['tone' => 'secondary'])
@php($tone = in_array($tone, ['success', 'warning', 'danger', 'info', 'secondary'], true) ? $tone : 'secondary')
<span {{ $attributes->class(['badge', 'bg-'.$tone.'-subtle', 'text-'.$tone]) }}>{{ $slot }}</span>
