@props(['tone' => 'info'])
@php($tone = in_array($tone, ['info', 'warning', 'danger'], true) ? $tone : 'info')
<div {{ $attributes->class(['alert', 'alert-'.$tone]) }}>{{ $slot }}</div>
