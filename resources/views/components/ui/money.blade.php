@props(['amount', 'tone' => null, 'sign' => false])
{{-- Importo con colore semantico: tone «in» per entrate e incassi (verde), «out» per spese e uscite (rosso). Lo zero resta neutro. --}}
@php($cents = (int) $amount)
<span {{ $attributes->class(['money', 'money-'.$tone => $tone !== null, 'is-zero' => $cents === 0]) }}>@if($sign && $cents !== 0 && $tone === 'out')− @endif{{ \App\Support\Money::format($cents) }}</span>
