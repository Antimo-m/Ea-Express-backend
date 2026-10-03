<x-app-layout title="Destinatari non affidabili">
    <header class="page-heading"><div><span class="eyebrow">ARCHIVIO INTERNO</span><h1>Destinatari non affidabili</h1><p>Precedenti di mancata consegna per destinatario assente. Le segnalazioni non bloccano le spedizioni.</p></div><span class="count-pill">{{ $activeProfiles }} destinatari con precedenti attivi</span></header>
    <x-ui.filters :reset="route('recipient-incidents.index')">
        <x-slot:search><x-ui.field name="q" label="Cerca destinatario" :value="request('q')" placeholder="Cerca nome, telefono, comune…" /></x-slot:search>
        <x-ui.filter-select name="state" label="Segnalazioni" default="active" :value="$state" :options="['active'=>'Attive','restored'=>'Ripristinate','all'=>'Tutto lo storico']" size="md" />
    </x-ui.filters>
    <section class="recipient-register-summary" aria-label="Riepilogo segnalazioni"><article class="surface p-3"><span>Destinatari non affidabili</span><strong>{{ $activeProfiles }}</strong></article><article class="surface p-3"><span>Episodi attivi</span><strong>{{ $activeIncidents }}</strong></article><article class="surface p-3"><span>Destinatari ripristinati</span><strong>{{ $totalProfiles - $activeProfiles }}</strong></article></section>

    <p class="data-caption">{{ $profiles->total() }} destinatari trovati</p>
    @forelse($profiles as $profile)
        <a class="surface recipient-person-card" href="{{ route('recipient-incidents.show', $profile) }}">
            <div><span class="data-label">Nome destinatario</span><x-orders.recipient-name :name="$profile->latestIncident->recipient['recipient_name']" :risk="['count' => $profile->active_count, 'last_at' => $profile->last_active_at]" tag="h2" />@if($profile->active_count === 0)<small class="text-secondary">Segnalazione rimossa · affidabilità ripristinata</small>@endif</div>
            <div><span class="data-label">Località</span><span>{{ $profile->latestIncident->recipient['delivery_city'] ?? 'Non indicata' }}</span></div>
            <span class="recipient-person-arrow" aria-hidden="true"><x-ui.icon name="arrow-right" /></span>
        </a>
    @empty
        <section class="surface empty-state"><x-ui.icon name="shield-check"/><h2>Nessun destinatario trovato</h2><p>Nessun precedente registrato per i filtri scelti. Le segnalazioni vengono registrate dagli esiti reali delle consegne.</p></section>
    @endforelse
    {{ $profiles->links('components.ui.pagination') }}
</x-app-layout>
