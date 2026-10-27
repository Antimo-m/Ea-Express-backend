<x-app-layout :title="'Ordini · '.request('zone')">
    <nav class="page-back" aria-label="Navigazione pagina"><x-ui.icon-button action="back" label="Torna indietro" :href="$returnUrl" text /></nav>
    <header class="page-heading"><div><span class="eyebrow">ORDINI DELLA ZONA</span><h1 class="text-break">{{ request('zone') }}</h1><p>{{ $period->start->format('d/m/Y') }} – {{ $period->end->format('d/m/Y') }} · {{ $orders->total() }} spedizioni · {{ request('scope') === 'late' ? 'Ritardi e arretrati' : 'Programmate nel periodo' }}</p></div></header>
    @include('rider-operations.orders')
</x-app-layout>
