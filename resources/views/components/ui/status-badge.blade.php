@props(['status' => null, 'tone' => null, 'label' => null])
@php
    $tone ??= $status?->tone();
    $label ??= $status?->label();
@endphp
<span {{ $attributes->class(['status-badge', 'status-pill', 'tone-'.$tone => $tone !== null]) }}>{{ $label ?? $slot }}</span>
