<x-app-layout title="Le mie consegne">
<div class="rider-day"><header class="dashboard-heading"><div><span class="eyebrow">EA-Express · LA TUA GIORNATA</span><h1>Le mie consegne</h1><p class="text-secondary">{{ $greeting }}, {{ auth()->user()->name }}. Ritiri e consegne assegnati alla tua gestione.</p></div><div class="date-chip">{{ $today }}</div></header>
<section class="metrics-grid mt-4" data-live-region="metrics">@foreach($metrics as $metric)<x-dashboard.metric :metric="$metric" />@endforeach</section>
<nav class="quick-links" aria-label="Accessi operativi"><a href="{{ route('pickups.index') }}">Ritiri assegnati</a><a href="{{ route('orders.in-progress') }}">Spedizioni in corso</a><a href="{{ route('messages.index') }}">Messaggi</a></nav>

<section class="surface operations-section" data-live-region="shipments"><h2 class="h4">Prossime spedizioni</h2>@forelse($shipments as $order)<x-orders.row :recipient-risk="$recipientRisks[$order->id] ?? null" :order="$order" />@empty<div class="surface p-4"><h3 class="h5">Nessuna spedizione assegnata</h3><p class="text-secondary">Le richieste compariranno dopo la presa in carico e l’assegnazione da parte di un amministratore.</p></div>@endforelse</section>
</div>
</x-app-layout>
