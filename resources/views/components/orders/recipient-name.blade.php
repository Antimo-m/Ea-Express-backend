@props(['name', 'risk' => null, 'tag' => 'span'])
@php
    $tag = in_array($tag, ['span', 'strong', 'h2', 'h3'], true) ? $tag : 'span';
@endphp
<div {{ $attributes->class(['recipient-identity', 'is-unreliable' => ($risk['count'] ?? 0) > 0]) }}>
    <{{ $tag }} class="recipient-name">{{ $name }}</{{ $tag }}>
    <x-orders.recipient-risk :risk="$risk" />
</div>
