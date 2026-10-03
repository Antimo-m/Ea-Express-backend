<x-app-layout title="Dettaglio destinatario">
    <nav class="page-back" aria-label="Navigazione pagina"><x-ui.icon-button action="back" :href="route('recipient-incidents.index', ['state' => $profile->active_count > 0 ? 'active' : 'restored'])" label="Destinatari non affidabili" text /></nav>
    <section class="surface recipient-detail-card">
        <header class="card-heading">
            <div><span class="eyebrow">DETTAGLIO DESTINATARIO</span><h1>{{ $recipient['recipient_name'] }}</h1><x-ui.status-badge :tone="$profile->active_count > 0 ? 'danger' : 'green'" :label="$profile->active_count > 0 ? 'Non affidabile' : 'Affidabile'" /></div>
            <div class="card-actions">@if($editableIncident)<x-recipient-incidents.correction :incident="$editableIncident" :id="'person-correction-'.$profile->id" />@endif</div>
        </header>
        <h2 class="h5">Informazioni personali</h2>
        <dl class="operation-facts">
            @foreach(['recipient_name'=>'Nome e cognome','recipient_phone'=>'Telefono','delivery_address'=>'Via / indirizzo','delivery_street_number'=>'Civico','delivery_postal_code'=>'CAP','delivery_city'=>'Località','delivery_province'=>'Provincia'] as $field=>$label)
                <div><dt>{{ $label }}</dt><dd>{{ ($recipient[$field] ?? '') ?: 'Non disponibile' }}</dd></div>
            @endforeach
        </dl>
        <p class="data-caption mb-0 mt-3">Dati dell’episodio più recente. Le rettifiche riguardano il singolo episodio e possono aggiornare l’affidabilità.</p>
    </section>
    <section class="recipient-history mt-4" aria-labelledby="recipient-history-title">
        <header class="section-heading"><h2 id="recipient-history-title">Cronologia ordini non ritirati</h2><span class="count-pill">{{ $incidents->total() }} episodi</span></header>
        <p class="data-caption">Episodi registrati di destinatario assente o mancata consegna, comprese le segnalazioni rimosse.</p>
        @foreach($incidents as $incident)
            <article class="surface recipient-detail-card mb-3">
                <header class="card-heading"><div><span class="data-label">Ordine</span><h3 class="h5 mb-1">{{ $incident->order->reference }}</h3><x-ui.status-badge :tone="$incident->dismissed_at ? 'neutral' : 'danger'" :label="$incident->dismissed_at ? 'Segnalazione rimossa' : 'Segnalazione attiva'" /></div><div class="card-actions">@unless($incident->dismissed_at)<x-recipient-incidents.correction :incident="$incident" />@endunless</div></header>
                <dl class="operation-facts">
                    <div><dt>Data episodio</dt><dd>{{ $incident->occurred_at->timezone('Europe/Rome')->format('d/m/Y H:i') }}</dd></div>
                    <div><dt>Cliente / negozio</dt><dd>{{ $incident->order->displayName() }}</dd></div>
                    <div><dt>Destinatario registrato</dt><dd>{{ $incident->recipient['recipient_name'] }}</dd></div>
                    <div><dt>Indirizzo registrato</dt><dd>{{ $incident->recipient['delivery_address'] }} {{ $incident->recipient['delivery_street_number'] ?? '' }} · {{ $incident->recipient['delivery_postal_code'] ?? '' }} {{ $incident->recipient['delivery_city'] }}</dd></div>
                    <div><dt>Motivo</dt><dd>{{ $incident->reason === 'recipient_absent' ? 'Destinatario assente / mancata consegna' : ($incident->reason ?: 'Non disponibile') }}</dd></div>
                    <div><dt>Stato attuale ordine</dt><dd><x-ui.status-badge :status="$incident->order->status" /></dd></div>
                    @if($incident->correction_reason)<div><dt>Ultima rettifica</dt><dd>{{ $incident->correction_reason }}</dd></div>@endif
                    @if($incident->dismissed_at)<div><dt>Segnalazione rimossa il</dt><dd>{{ $incident->dismissed_at->timezone('Europe/Rome')->format('d/m/Y H:i') }}</dd></div>@endif
                </dl>
                <div class="row-actions mt-3"><x-ui.icon-button icon="box-arrow-up-right" action="open" :href="route('orders.show', $incident->order)" label="Apri ordine" text /><x-ui.icon-button action="history" :href="route('audits.index', ['type' => 'recipient_incidents', 'id' => $incident->id])" label="Storico rettifiche" text /></div>
            </article>
        @endforeach
        {{ $incidents->links('components.ui.pagination') }}
    </section>
</x-app-layout>
