@once('balance-receipt-dialogs-'.$order->slug)
@if(auth()->user()->role === \App\UserRole::Admin && ! $order->unverified_refund_exists)
    @php($activeReversal = $order->receiptReversals->whereNull('restored_at')->last())
    @if($order->receipt_review_required || ($order->paid_at && (int) $order->payments_sum_cash_cents !== $order->price_cents) || ($order->receipt_voided_at && ! $activeReversal))
        @unless($order->receipt_voided_at)
            <x-ui.modal :id="'receipt-reconcile-'.$order->slug" title="Riconcilia incasso" :description="$order->reference">
                <p>Confronta ricevute e storico prima di confermare. Gli importi e le date dei movimenti esistenti restano conservati. Questa operazione non registra un nuovo pagamento del cliente.</p>
                <form method="post" action="{{ route('payments.store', $order) }}" data-financial-form class="form-stack">
                    @csrf<input type="hidden" name="action" value="reconcile"><input type="hidden" name="version" value="{{ $order->version }}">
                    @if($order->paid_at && (int) $order->payments_sum_cash_cents !== $order->price_cents)
                        <input type="hidden" name="resolution" value="partial">
                        <p>Il saldo risulta completo, ma sono registrati {{ \App\Support\Money::format((int) $order->payments_sum_cash_cents) }} su {{ \App\Support\Money::format($order->price_cents) }}. La riconciliazione riapre soltanto il residuo, senza modificare l’importo già ricevuto.</p>
                    @elseif($order->paid_at)
                        <input type="hidden" name="resolution" value="links">
                        <p>Il saldo è già completo. Verranno collegati soltanto gli storni storici che corrispondono in modo univoco agli incassi precedenti, senza modificare alcun saldo.</p>
                    @else
                        <input type="hidden" name="resolution" value="refunded">
                        <p>Conferma soltanto se hai verificato la prova del rimborso storico. Lo storno, da solo, non dimostra la restituzione. Il ripristino mancante viene tracciato nel periodo aperto senza creare denaro.</p>
                    @endif
                    <x-ui.field name="note" :id="'reconcile-reason-'.$order->slug" label="Documenti verificati e motivo della riconciliazione" maxlength="500" required />
                    <label class="form-check"><input class="form-check-input" type="checkbox" name="confirmed" value="1" required><span class="form-check-label">Ho verificato lo storico e la situazione effettiva del denaro.</span></label>
                    <x-ui.icon-button type="submit" action="confirm" label="Conferma riconciliazione verificata" text />
                </form>
            </x-ui.modal>
        @endunless
    @else
        @unless($order->paid_at || $order->receipt_voided_at || ($order->pendingAccount && $order->pendingAccount->state !== 'cancelled'))
            <x-ui.modal :id="'receipt-'.$order->slug" title="Registra incasso" :description="$order->reference">
                <div class="receipt-confirmation"><span>Residuo da registrare</span><strong>{{ \App\Support\Money::format($order->price_cents - (int) $order->payments_sum_cash_cents) }}</strong></div>
                <form method="post" action="{{ route('payments.store', $order) }}" data-financial-form class="form-stack">
                    @csrf<input type="hidden" name="version" value="{{ $order->version }}"><input type="hidden" name="action" value="receive">
                    @if($order->shipping_type === 'external')<x-ui.field name="received_amount" :id="'received-amount-'.$order->slug" label="Importo effettivamente incassato (€)" :value="number_format(($order->price_cents - (int) $order->payments_sum_cash_cents) / 100, 2, '.', '')" inputmode="decimal" pattern="[0-9]{1,6}([.,][0-9]{1,2})?" required help="L’incasso diretto salda il residuo. Per importi parziali usa Sospesi." />@endif
                    <x-ui.icon-button type="submit" action="receive" label="Registra incasso" text />
                </form>
            </x-ui.modal>
        @endunless
        @if($order->receipt_voided_at && (int) $order->payments_sum_cash_cents > 0)
        <x-ui.modal :id="'receipt-refund-'.$order->slug" title="Conferma rimborso pagato" :description="$order->reference">
            <p>Registra soltanto denaro effettivamente restituito: {{ \App\Support\Money::format((int) $order->payments_sum_cash_cents) }}. Se il rimborso è già presente nello storico, non registrarlo nuovamente.</p>
            <form method="post" action="{{ route('payments.store', $order) }}" data-financial-form class="form-stack">@csrf<input type="hidden" name="action" value="refund"><input type="hidden" name="version" value="{{ $order->version }}"><x-ui.field name="note" :id="'refund-reason-'.$order->slug" label="Prova del pagamento e motivo" maxlength="500" required /><x-ui.icon-button type="submit" action="confirm" label="Conferma rimborso pagato" text /></form>
        </x-ui.modal>
        @endif
        <x-ui.modal :id="'receipt-state-'.$order->slug" :title="$order->receipt_voided_at ? 'Ripristina incasso' : 'Storna incasso'" :description="$order->reference">
            <form method="post" action="{{ route('payments.store', $order) }}" data-financial-form class="form-stack">
                @csrf<input type="hidden" name="version" value="{{ $order->version }}"><input type="hidden" name="action" value="{{ $order->receipt_voided_at ? 'restore' : 'reverse' }}">
                <p>{{ $order->receipt_voided_at ? 'Il ripristino rimuove lo storno attivo e riporta l’incasso Da registrare. Il totale lordo resta invariato fino alla nuova registrazione del pagamento.' : (($order->paid_at || (int) $order->payments_sum_amount_cents > 0) ? 'Lo storno rettifica il risultato economico. Il denaro resta in cassa e diventa un rimborso da effettuare: registralo separatamente soltanto dopo averlo restituito.' : 'Nessuna uscita di cassa verrà registrata: l’incasso non è stato ricevuto.') }}</p>
                @unless($order->receipt_voided_at)<p class="register-note">Rettifica contabile: {{ \App\Support\Money::format((int) $order->payments_sum_amount_cents) }}. Il residuo non incassato viene annullato.</p>@endunless
                <x-ui.field name="note" :id="'receipt-reason-'.$order->slug" :label="$order->receipt_voided_at ? 'Motivo del ripristino' : 'Motivo dello storno'" maxlength="500" required />
                <x-ui.icon-button type="submit" :action="$order->receipt_voided_at ? 'restore' : 'delete'" :label="$order->receipt_voided_at ? 'Ripristina incasso' : 'Conferma storno'" text />
            </form>
        </x-ui.modal>
    @endif
@endif
@endonce
