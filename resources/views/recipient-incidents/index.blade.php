<x-app-layout title="Destinatari non affidabili">
    <header class="page-heading"><div><span class="eyebrow">QUALITÀ DELLE CONSEGNE</span><h1>Destinatari non affidabili</h1><p>Precedenti verificati, persone riconoscibili e una cronologia sempre consultabile.</p></div></header>
    <section class="recipient-register-summary" aria-label="Riepilogo segnalazioni"><x-ui.metric label="Destinatari con precedenti attivi" :value="$activeProfiles" icon="person-exclamation" tone="negative" /><x-ui.metric label="Episodi attivi" :value="$activeIncidents" icon="exclamation-circle" /><x-ui.metric label="Destinatari ripristinati" :value="$totalProfiles - $activeProfiles" icon="shield-check" tone="positive" /></section>
    <x-ui.filters :reset="route('recipient-incidents.index')">
        <x-slot:search><x-ui.field name="q" label="Cerca destinatario" :value="request('q')" placeholder="Cerca nome, telefono, comune…" /></x-slot:search>
        <x-ui.filter-select name="state" label="Segnalazioni" default="active" :value="$state" :options="['active'=>'Attive','restored'=>'Ripristinate','all'=>'Tutto lo storico']" size="md" />
    </x-ui.filters>
    @if($profiles->isNotEmpty())
    <x-ui.directory label="Registro destinatari" search-label="Filtra i risultati di questa pagina" class="recipient-directory">
        <x-slot:summary><p>{{ $profiles->total() }} destinatari trovati</p></x-slot:summary>
        <x-slot:list>@foreach($profiles as $profile)
            @php($person = $profile->latestIncident->recipient)
            <a href="{{ route('recipient-incidents.show', $profile) }}" class="directory-person" data-directory-select="{{ $profile->slug }}" data-directory-name="{{ $person['recipient_name'] }} {{ $person['delivery_city'] ?? '' }}" aria-controls="recipient-{{ $profile->slug }}" @if($loop->first) aria-current="true" @endif>
                <x-ui.identity-avatar :name="$person['recipient_name']" /><span class="directory-person-copy"><strong>{{ $person['recipient_name'] }}</strong><span>{{ $person['delivery_city'] ?? 'Località non indicata' }}</span><small @class(['recipient-risk-count', 'is-restored' => $profile->active_count === 0])>{{ $profile->active_count > 0 ? ($profile->active_count === 1 ? '1 precedente attivo' : $profile->active_count.' precedenti attivi') : 'Segnalazione rimossa · affidabilità ripristinata' }}</small></span><x-ui.icon name="chevron-right" />
            </a>
        @endforeach</x-slot:list>
        <x-slot:pagination>{{ $profiles->links('components.ui.pagination') }}</x-slot:pagination>
        @foreach($profiles as $profile)
            @php($person = $profile->latestIncident->recipient)
            <section class="directory-detail recipient-profile" id="recipient-{{ $profile->slug }}" data-directory-panel="{{ $profile->slug }}" @if(!$loop->first) hidden @endif aria-labelledby="recipient-heading-{{ $profile->slug }}">
                <header class="profile-heading"><div class="profile-identity"><x-ui.identity-avatar :name="$person['recipient_name']" class="identity-avatar-lg" /><div><span class="eyebrow">DESTINATARIO</span><h2 id="recipient-heading-{{ $profile->slug }}" tabindex="-1" data-directory-heading>{{ $person['recipient_name'] }}</h2><p>{{ $person['delivery_city'] ?? 'Località non indicata' }}</p></div></div><x-ui.status-badge :tone="$profile->active_count > 0 ? 'danger' : 'green'" :label="$profile->active_count > 0 ? 'Precedenti attivi' : 'Affidabilità ripristinata'" /></header>
                <dl class="profile-metrics"><div><dt>Precedenti attivi</dt><dd>{{ $profile->active_count }}</dd></div><div><dt>Episodi registrati</dt><dd>{{ $profile->incidents_count }}</dd></div></dl>
                <section class="profile-section"><header><h3>Riferimenti del destinatario</h3><x-ui.icon name="person-vcard" /></header><dl class="profile-facts"><div><dt>Località registrata</dt><dd>{{ $person['delivery_city'] ?? 'Non indicata' }}</dd></div><div><dt>Contatti e dati personali</dt><dd><a href="{{ route('recipient-incidents.show', $profile) }}">Consulta la scheda del destinatario <x-ui.icon name="arrow-up-right" /></a></dd></div></dl></section>
                <section class="profile-section incident-latest"><header><h3>Episodio più recente</h3><time datetime="{{ $profile->latestIncident->occurred_at->toIso8601String() }}">{{ $profile->latestIncident->occurred_at->timezone('Europe/Rome')->format('d/m/Y H:i') }}</time></header><p>{{ $profile->latestIncident->reason === 'recipient_absent' ? 'Destinatario assente / mancata consegna' : ($profile->latestIncident->reason ?: 'Motivo non disponibile') }}</p><a href="{{ route('orders.show', $profile->latestIncident->order) }}" class="incident-order-link"><x-ui.icon name="box-seam" /><span>{{ $profile->latestIncident->order->reference }}</span><x-ui.icon name="arrow-up-right" /></a></section>
                <p class="profile-footnote">Le segnalazioni non bloccano le spedizioni. I riferimenti provengono dall’episodio più recente.</p>
                <footer class="profile-footer"><button type="button" class="btn btn-outline-secondary directory-return" data-directory-return><x-ui.icon name="arrow-up" /> Elenco destinatari</button><a class="btn btn-primary" href="{{ route('recipient-incidents.show', $profile) }}"><x-ui.icon name="clock-history" /> Cronologia e rettifiche</a></footer>
            </section>
        @endforeach
    </x-ui.directory>
    @else<section class="surface empty-state"><x-ui.icon name="shield-check"/><h2>Nessun destinatario trovato</h2><p>Nessun precedente registrato per i filtri scelti. Le segnalazioni derivano dagli esiti reali delle consegne.</p></section>@endif
</x-app-layout>
