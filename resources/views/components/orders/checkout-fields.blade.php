@props(['data'])
{{-- Ripropone i dati del modulo come campi nascosti, per tornare alla modifica o confermare il riepilogo. --}}
@foreach($data as $name => $value)
    @if(! is_array($value))<input type="hidden" name="{{ $name }}" value="{{ $value }}">@else
        @foreach($value as $index => $item)@foreach($item as $field => $number)<input type="hidden" name="{{ $name }}[{{ $index }}][{{ $field }}]" value="{{ $number }}">@endforeach @endforeach
    @endif
@endforeach
