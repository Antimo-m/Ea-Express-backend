@props(['order', 'transitions', 'riders' => collect()])
@php
    $exceptions = [\App\OrderStatus::Rejected, \App\OrderStatus::Cancelled, \App\OrderStatus::DeliveryIssue, \App\OrderStatus::DeliveryAttempted];
    $primary = $order->status === \App\OrderStatus::Accepted ? \App\OrderStatus::RiderArriving : collect($transitions)->first(fn ($status) => !in_array($status, $exceptions));
    $alternatives = collect($transitions)->reject(fn ($status) => $status === $primary);
    $needsPriceAgreement = $primary === \App\OrderStatus::Accepted && $order->pricing_version === 1 && ($order->price_state === 'awaiting_customer' || ($order->price_cents === null && $order->quoted_price_cents === null));
    if ($needsPriceAgreement) { $primary = null; }
    $formId = 'primary-transition-'.$order->id;
@endphp
<section class="surface workflow-panel" id="next-action">
    <header class="card-heading">
        <div><span class="eyebrow">IL PROSSIMO PASSO</span><h2 class="h5 mb-0">{{ $primary ? $primary->actionLabel() : 'Gestisci l’imprevisto' }}</h2></div>
        <div class="card-actions workflow-primary-actions">
            @if($primary)<x-ui.icon-button type="submit" :form="$formId" action="confirm" :label="$primary->actionLabel()" text />@endif
            <x-ui.icon-button action="message" :href="route('messages.show', $order)" label="Apri messaggi del cliente" />
            @foreach($alternatives->filter(fn ($next) => in_array($next, [\App\OrderStatus::Cancelled, \App\OrderStatus::Rejected], true)) as $next)
                <x-orders.transition-action :order="$order" :status="$next" />
            @endforeach
        </div>
    </header>
    <dl class="operation-facts mb-3"><div><dt>Stato attuale</dt><dd><x-ui.status-badge :status="$order->status" /></dd></div><div><dt>Rider</dt><dd>{{ $order->rider?->name ?: 'Da assegnare' }}</dd></div></dl>
    <p class="small text-secondary">{{ $order->status === \App\OrderStatus::Received ? 'Controlla percorso e orari, poi verifica la tariffa e prendi in carico la richiesta.' : 'Conferma il passaggio solo dopo aver effettuato l’operazione.' }}</p>
    @if($needsPriceAgreement)<p class="text-secondary">Concorda la tariffa prima di prendere in carico.</p>@endif
    @if($primary)
        <form id="{{ $formId }}" method="post" action="{{ route('orders.update', $order) }}" class="form-stack">
            @csrf @method('patch')
            <input type="hidden" name="version" value="{{ $order->version }}">
            <input type="hidden" name="status" value="{{ $primary->value }}">
            @if($primary === \App\OrderStatus::Accepted)
                <div><label class="form-label" for="assigned-rider">Rider da assegnare</label><select id="assigned-rider" name="rider_id" class="form-select" required><option value="">Seleziona un Rider</option>@foreach($riders as $rider)<option value="{{ $rider->id }}" @selected(old('rider_id') == $rider->id)>{{ $rider->name }}{{ $rider->id === auth()->id() ? ' · Tu (Admin)' : '' }}</option>@endforeach</select></div>
                @if($riders->isEmpty())<p class="text-danger">Crea o riattiva un Rider prima di confermare la presa in carico.</p>@endif
                @if($order->pricing_version !== 1)<x-ui.field name="price" label="Costo spedizione (€)" inputmode="decimal" :value="old('price')" placeholder="8,50" help="Compenso per il servizio, distinto dal valore della merce." required />@endif
            @endif
            @if($primary === \App\OrderStatus::Rescheduled)
                <x-ui.field name="estimated_at" label="Nuova previsione" type="datetime-local" :value="old('estimated_at')" required />
                <x-ui.field name="note" label="Motivo della riprogrammazione" :value="old('note')" required maxlength="2000" />
            @endif
        </form>
        @unless($primary === \App\OrderStatus::Rescheduled)
            <x-ui.action-dialog :id="'transition-note-'.$order->id" action="edit" title="Aggiungi nota al passaggio">
                <label for="public_note" class="form-label">Messaggio visibile nel tracking</label><textarea id="public_note" name="public_note" form="{{ $formId }}" class="form-control" maxlength="500" rows="2">{{ old('public_note') }}</textarea>
                <label for="primary-note" class="form-label mt-2">Nota interna (facoltativa)</label><textarea id="primary-note" name="note" form="{{ $formId }}" class="form-control" rows="2" maxlength="2000">{{ old('note') }}</textarea>
                <div class="modal-actions"><button type="button" class="btn btn-outline-secondary" data-dialog-close>Torna al passaggio</button></div>
            </x-ui.action-dialog>
        @endunless
    @endif
    @if($alternatives->contains(fn ($next) => !in_array($next, [\App\OrderStatus::Cancelled, \App\OrderStatus::Rejected], true)))
        <section class="workflow-alternatives"><h3 class="h6">Altre azioni e imprevisti</h3><div class="card-actions">
            @foreach($alternatives->reject(fn ($next) => in_array($next, [\App\OrderStatus::Cancelled, \App\OrderStatus::Rejected], true)) as $next)<x-orders.transition-action :order="$order" :status="$next" />@endforeach
        </div></section>
    @endif
</section>
