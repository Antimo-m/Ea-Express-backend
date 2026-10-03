<x-app-layout title="Tracking">
    <header class="page-heading"><div><span class="eyebrow">VISIBILITÀ DELLE SPEDIZIONI</span><h1>La strada, in tempo reale.</h1><p>Rider, consegne e ultimi aggiornamenti in un unico spazio operativo.</p></div></header>
    <x-orders.live-map fleet />
    <section class="surface operations-section mt-4" data-live-region="orders"><header><h2>Spedizioni tracciate</h2></header><div class="order-table-surface">@forelse($orders as $order)<x-orders.row :order="$order" />@empty<p class="text-secondary">Nessun tracking attivo. Il tracking si attiva al primo avvio del ritiro.</p><a class="btn btn-primary" href="{{ route('orders.in-progress') }}">Apri le spedizioni</a>@endforelse</div>{{ $orders->links('components.ui.pagination') }}</section>
</x-app-layout>
