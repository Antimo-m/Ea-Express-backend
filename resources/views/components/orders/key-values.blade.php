@props(['items'])
{{-- Coppie etichetta–valore del riepilogo (stessa resa del portale clienti): le voci vuote vengono omesse. --}}
@php($rows = array_filter($items, fn ($value): bool => $value !== null && $value !== '' && $value !== false))
@if($rows)
<dl {{ $attributes->class('key-values') }}>@foreach($rows as $label => $value)<div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>@endforeach</dl>
@endif
