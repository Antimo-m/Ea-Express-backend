@if(auth()->user()->role === \App\UserRole::Admin)
    @if($order->pendingAccount && $order->pendingAccount->state !== 'cancelled')
        <a class="btn btn-outline-secondary btn-sm" href="{{ route('pending.index', ['slug' => $order->pendingAccount->slug, 'direction' => $order->pendingAccount->direction]) }}">Gestisci sospeso</a>
    @else
        @unless($order->paid_at || $order->receipt_voided_at)
            <x-ui.action-dialog :id="'receipt-'.$order->slug" action="receive" title="Registra incasso" :description="$order->reference" text>
                <div class="receipt-confirmation"><span>Tariffa prevista</span><strong>{{ \App\Support\Money::format($order->price_cents) }}</strong></div>
                <form method="post" action="{{ route('payments.store', $order) }}" data-financial-form class="form-stack">
                    @csrf<input type="hidden" name="version" value="{{ $order->version }}"><input type="hidden" name="action" value="receive">
                    @if($order->shipping_type === 'external')<x-ui.field name="received_amount" :id="'received-amount-'.$order->slug" label="Importo effettivamente incassato (€)" :value="number_format($order->price_cents / 100, 2, '.', '')" inputmode="decimal" pattern="[0-9]{1,6}([.,][0-9]{1,2})?" required help="L’incasso diretto salda il residuo. Per importi parziali usa Sospesi." />@endif
                    <x-ui.icon-button type="submit" action="receive" label="Registra incasso" text />
                </form>
            </x-ui.action-dialog>
        @endunless
        <details class="row-actions-menu"><summary class="icon-button" aria-label="Altre operazioni per {{ $order->reference }}" title="Altre operazioni"><x-ui.icon name="three-dots" /></summary><div class="row-actions-popover">
            <x-ui.icon-button :action="$order->receipt_voided_at ? 'restore' : 'delete'" :label="$order->receipt_voided_at ? 'Ripristina incasso' : 'Storna incasso'" :data-dialog-open="'receipt-state-'.$order->slug" :aria-controls="'receipt-state-'.$order->slug" aria-haspopup="dialog" text />
            <a class="btn btn-outline-secondary btn-sm" href="{{ route('audits.index', ['type' => 'orders', 'slug' => $order->slug]) }}">Storico dell’incasso</a>
        </div></details>
            <x-ui.modal :id="'receipt-state-'.$order->slug" :title="$order->receipt_voided_at ? 'Ripristina incasso' : 'Storna incasso'" :description="$order->reference">
                <form method="post" action="{{ route('payments.store', $order) }}" data-financial-form class="form-stack">
                    @csrf<input type="hidden" name="version" value="{{ $order->version }}"><input type="hidden" name="action" value="{{ $order->receipt_voided_at ? 'restore' : 'reverse' }}">
                    <p>{{ $order->receipt_voided_at ? 'Il ripristino riporta la spedizione tra gli incassi da registrare. Non registra un pagamento.' : ($order->paid_at ? 'Lo storno compensa l’incasso registrato e lo sposta in Storni.' : 'Nessuna uscita di cassa verrà registrata: l’incasso non è stato ricevuto.') }}</p>
                    <x-ui.field name="note" :id="'receipt-reason-'.$order->slug" :label="$order->receipt_voided_at ? 'Motivo del ripristino' : 'Motivo dello storno'" maxlength="500" required />
                    <x-ui.icon-button type="submit" :action="$order->receipt_voided_at ? 'restore' : 'delete'" :label="$order->receipt_voided_at ? 'Ripristina incasso' : 'Conferma storno'" text />
                </form>
            </x-ui.modal>
    @endif
@endif
