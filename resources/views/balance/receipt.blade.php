<article class="surface receipt-row">
    <div class="receipt-identity">
        <span class="data-label">Cliente / negozio</span><a class="fw-semibold" href="{{ route('orders.show', $order) }}">{{ $order->displayName() }}</a>
        <p class="small text-secondary text-break mb-1">Ordine: {{ $order->reference }}</p>
        <strong>Tariffa prevista: {{ \App\Support\Money::format($order->price_cents) }}</strong>
        @if($order->paid_at)<p class="small mb-0">Incasso effettivo: {{ \App\Support\Money::format((int) $order->payments_sum_amount_cents) }}</p>@endif
        @if($order->receipt_voided_at)<p class="small text-secondary mb-0">Stornato il {{ $order->receipt_voided_at->timezone('Europe/Rome')->format('d/m/Y H:i') }} · {{ $order->receipt_void_reason }}</p>@endif
    </div>
    <div class="row-actions">
        @if($order->pendingAccount && $order->pendingAccount->state !== 'cancelled')
            <x-ui.pending-status :account="$order->pendingAccount" />
            @if(auth()->user()->role === \App\UserRole::Admin)<a class="btn btn-outline-primary btn-sm" href="{{ route('pending.index', ['id' => $order->pendingAccount->id, 'direction' => $order->pendingAccount->direction]) }}">Gestisci sospeso</a>@endif
        @else
            @unless($order->paid_at || $order->receipt_voided_at)
                <x-ui.action-dialog :id="'receipt-'.$order->id" action="receive" title="Registra incasso" :description="$order->reference">
                    <form method="post" action="{{ route('payments.store', $order) }}" data-financial-form class="form-stack">
                        @csrf<input type="hidden" name="version" value="{{ $order->version }}"><input type="hidden" name="action" value="receive">
                        <p>Tariffa prevista: <strong>Tariffa prevista: {{ \App\Support\Money::format($order->price_cents) }}</strong>
        @if($order->paid_at)<p class="small mb-0">Incasso effettivo: {{ \App\Support\Money::format((int) $order->payments_sum_amount_cents) }}</p>@endif.</p>
                        @if($order->shipping_type === 'external')<x-ui.field name="received_amount" :id="'received-amount-'.$order->id" label="Importo effettivamente incassato (€)" :value="old('received_amount', number_format($order->price_cents / 100, 2, '.', ''))" inputmode="decimal" pattern="[0-9]{1,6}([.,][0-9]{1,2})?" required help="La tariffa originale resta invariata. Questo importo entra nel totale lordo incassato."/>@endif
                        <x-ui.icon-button type="submit" action="receive" label="Registra incasso" text />
                    </form>
                </x-ui.action-dialog>
            @endunless
            <x-ui.action-dialog :id="'receipt-state-'.$order->id" :action="$order->receipt_voided_at ? 'restore' : 'delete'" :title="$order->receipt_voided_at ? 'Ripristina incasso' : 'Storna incasso'" :description="$order->reference">
                <form method="post" action="{{ route('payments.store', $order) }}" data-financial-form class="form-stack">
                    @csrf<input type="hidden" name="version" value="{{ $order->version }}"><input type="hidden" name="action" value="{{ $order->receipt_voided_at ? 'restore' : 'reverse' }}">
                    <p class="text-secondary small">{{ $order->receipt_voided_at ? 'Il ripristino riporta la spedizione tra gli incassi da registrare. Non registra un pagamento.' : ($order->paid_at ? 'Lo storno compensa l’incasso registrato e lo sposta in Storni.' : 'L’incasso viene spostato in Storni. Nessuna uscita di cassa viene registrata perché non è stato ricevuto denaro.') }}</p>
                    <x-ui.field name="note" :id="'receipt-reason-'.$order->id" :label="$order->receipt_voided_at ? 'Motivo del ripristino' : 'Motivo dello storno'" maxlength="500" required />
                    <x-ui.icon-button type="submit" :action="$order->receipt_voided_at ? 'restore' : 'delete'" :label="$order->receipt_voided_at ? 'Ripristina incasso' : 'Conferma storno'" text />
                </form>
            </x-ui.action-dialog>
        @endif
        @if(auth()->user()->role === \App\UserRole::Admin)<x-ui.icon-button action="history" label="Storico dell’incasso" :href="route('audits.index', ['type' => 'orders', 'id' => $order->id])" />@endif
    </div>
</article>
