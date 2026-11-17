@props(['order', 'transitions', 'riders' => collect()])
@php
    $exceptions = [\App\OrderStatus::Rejected, \App\OrderStatus::Cancelled, \App\OrderStatus::DeliveryIssue, \App\OrderStatus::DeliveryAttempted];
    $primary = collect($transitions)->first(fn ($status) => !in_array($status, $exceptions));
    $alternatives = collect($transitions)->reject(fn ($status) => $status === $primary);
    $needsPriceAgreement = $primary === \App\OrderStatus::Accepted && $order->pricing_version === 1 && ($order->price_state === 'awaiting_customer' || ($order->price_cents === null && $order->quoted_price_cents === null));
    if ($needsPriceAgreement) { $primary = null; }
    $formId = 'primary-transition-'.$order->slug;
    $problemDialog = 'workflow-problem-'.$order->slug;
    $problems = collect([\App\OrderStatus::DeliveryAttempted, \App\OrderStatus::DeliveryIssue, \App\OrderStatus::Cancelled])->filter(fn ($status) => $alternatives->contains($status))->values();
    $others = $alternatives->reject(fn ($status) => in_array($status, [...$exceptions, \App\OrderStatus::Accepted], true))->values();
@endphp
<section class="surface workflow-panel" id="next-action">
    <header class="card-heading">
        <div><span class="eyebrow">IL PROSSIMO PASSO</span><h2 class="h5 mb-0">{{ $primary ? $primary->actionLabel() : ($needsPriceAgreement ? 'Concorda la tariffa' : 'Gestisci l’imprevisto') }}</h2></div>
        <div class="card-actions workflow-primary-actions">
            @if($primary && $primary !== \App\OrderStatus::Accepted)<x-ui.icon-button type="submit" :form="$formId" action="confirm" :label="$primary->actionLabel()" text />@endif
        </div>
    </header>
    <dl class="operation-facts workflow-context">
        <div><dt>Stato attuale</dt><dd><x-ui.status-badge :status="$order->status" /></dd></div>
        @if($primary)<div><dt>Passaggio previsto</dt><dd>{{ $primary->label() }}</dd></div>@endif
        <div><dt>Rider</dt><dd>{{ $order->rider?->name ?: 'Da assegnare' }}</dd></div>
        <div><dt>Consegna a</dt><dd>{{ $order->recipient_name }} · {{ $order->delivery_city }}</dd></div>
    </dl>
    <p class="small text-secondary">{{ $order->status === \App\OrderStatus::Received ? 'Controlla percorso e orari, poi verifica la tariffa e prendi in carico la richiesta.' : 'Conferma il passaggio solo dopo aver effettuato l’operazione.' }}</p>
    @if($needsPriceAgreement)<p class="workflow-prerequisite">La richiesta attende una tariffa concordata. <a href="#order-pricing">Verifica la tariffa</a> prima della presa in carico.</p>@endif
    @if($primary)
        <form id="{{ $formId }}" data-order-action method="post" action="{{ route('orders.update', $order) }}" class="form-stack">
            @csrf @method('patch')
            <input type="hidden" name="version" value="{{ $order->version }}">
            <input type="hidden" name="status" value="{{ $primary->value }}">
            @if($primary === \App\OrderStatus::Accepted)
                @if($order->pricing_version !== 1)<x-ui.field name="price" label="Costo spedizione (€)" inputmode="decimal" :value="old('price')" placeholder="8,50" help="Compenso per il servizio, distinto dal valore della merce." required />@endif
                <div class="rider-assignment-controls"><div><label class="form-label" for="assigned-rider">Rider da assegnare</label><select id="assigned-rider" data-inline-label="Rider" name="rider_id" class="form-select" required><option value="">Seleziona un Rider</option>@foreach($riders as $rider)<option value="{{ $rider->slug }}" @selected(old('rider_id') == $rider->slug)>{{ $rider->name }}{{ $rider->slug === auth()->user()->slug ? ' · Tu (Admin)' : '' }}</option>@endforeach</select></div><x-ui.icon-button action="confirm" type="submit" label="Conferma presa in carico e Rider" /></div>
                @if($riders->isEmpty())<p class="text-danger">Crea o riattiva un Rider prima di confermare la presa in carico.</p>@endif
            @endif
            @if($primary === \App\OrderStatus::Rescheduled)
                <x-ui.field name="estimated_at" label="Nuova previsione" type="datetime-local" :value="old('estimated_at')" required />
                <x-ui.field name="note" label="Motivo della riprogrammazione" :value="old('note')" required maxlength="2000" />
            @endif
        </form>

    @endif
    @if($alternatives->contains(\App\OrderStatus::Rejected))
        <footer class="workflow-secondary-actions"><span>Interrompi la richiesta</span><div class="row-actions">
            <x-orders.transition-action :order="$order" :status="\App\OrderStatus::Rejected" />
        </div></footer>
    @endif
    @if($problems->isNotEmpty())
        <footer class="workflow-problem">
            <button type="button" class="btn workflow-problem-button" data-dialog-open="{{ $problemDialog }}" aria-controls="{{ $problemDialog }}" aria-haspopup="dialog"><x-ui.icon name="exclamation-triangle" /><span>Segnala un problema</span></button>
        </footer>
        <x-ui.modal :id="$problemDialog" title="Segnala un problema" description="Scegli cosa è successo: ogni segnalazione viene registrata nella cronologia dell’ordine." :data-dialog-initial="$errors->any() && in_array(old('status'), $problems->map->value->all(), true) ? 'true' : null">
            <div class="workflow-problem-options">
                @foreach($problems as $problem)
                    @php
                        $option = match ($problem) {
                            \App\OrderStatus::DeliveryAttempted => ['icon' => 'person-x', 'title' => 'Destinatario non presente', 'text' => 'Registra il tentativo di consegna e segnala il destinatario come non affidabile.', 'note' => 'Nota per lo storico', 'default' => 'Destinatario non presente all’indirizzo di consegna.'],
                            \App\OrderStatus::DeliveryIssue => ['icon' => 'exclamation-octagon', 'title' => 'Altro problema di consegna', 'text' => 'Sospende la spedizione finché l’imprevisto non viene risolto.', 'note' => 'Descrivi il problema', 'default' => ''],
                            default => ['icon' => 'x-octagon', 'title' => 'Annulla ordine', 'text' => 'Chiude definitivamente la spedizione. Il motivo è obbligatorio e resta nello storico.', 'note' => 'Motivo dell’annullamento', 'default' => ''],
                        };
                        $cancel = $problem === \App\OrderStatus::Cancelled;
                        $noteId = 'problem-note-'.$order->slug.'-'.$problem->value;
                    @endphp
                    <details @class(['workflow-problem-option', 'is-danger' => $cancel]) name="problem-{{ $order->slug }}" @if(old('status') === $problem->value) open @endif>
                        <summary><span class="workflow-problem-icon" aria-hidden="true"><x-ui.icon :name="$option['icon']" /></span><span><strong>{{ $option['title'] }}</strong><small>{{ $option['text'] }}</small></span><x-ui.icon name="chevron-down" class="workflow-problem-chevron" /></summary>
                        <form data-order-action method="post" action="{{ route('orders.update', $order) }}" class="form-stack">
                            @csrf @method('patch')
                            <input type="hidden" name="version" value="{{ $order->version }}">
                            <input type="hidden" name="status" value="{{ $problem->value }}">
                            @if($cancel)<input type="hidden" name="cancellation_reason" value="other">@endif
                            <x-ui.field :id="$noteId" name="note" :label="$option['note']" :value="old('status') === $problem->value ? old('note') : $option['default']" :minlength="$cancel ? 5 : null" maxlength="2000" required />
                            <div class="workflow-problem-submit">
                                @if($cancel)
                                    <x-ui.icon-button type="submit" action="delete" label="Annulla ordine" :data-confirm="'Annulla ordine'" :data-confirm-name="$order->displayName()" text />
                                @else
                                    <button type="submit" class="btn btn-success">{{ $problem === \App\OrderStatus::DeliveryAttempted ? 'Registra destinatario assente' : 'Registra il problema' }}</button>
                                @endif
                            </div>
                        </form>
                    </details>
                @endforeach
            </div>
        </x-ui.modal>
    @endif
</section>
    @if($others->isNotEmpty())
        <details class="workflow-alternatives"><summary>Altre operazioni</summary><div class="card-actions">
            @foreach($others as $next)<x-orders.transition-action :order="$order" :status="$next" />@endforeach
        </div></details>
    @endif
