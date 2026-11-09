@if(auth()->user()->role === \App\UserRole::Admin)
    @php($activeReversal = $order->receiptReversals->whereNull('restored_at')->last())
    @if($order->receipt_review_required || ($order->paid_at && (int) $order->payments_sum_cash_cents !== $order->price_cents) || ($order->receipt_voided_at && ! $activeReversal))
        <span class="status-pill tone-orange">Storico da riconciliare</span>
        <a class="btn btn-outline-secondary btn-sm" href="{{ route('audits.index', ['type' => 'orders', 'slug' => $order->slug]) }}">Verifica movimenti</a>
        @unless($order->receipt_voided_at)
            <x-ui.icon-button action="confirm" label="Riconcilia incasso" :data-dialog-open="'receipt-reconcile-'.$order->slug" :aria-controls="'receipt-reconcile-'.$order->slug" aria-haspopup="dialog" text />
        @endunless
    @else
        @if($order->pendingAccount && $order->pendingAccount->state !== 'cancelled')
            <a class="btn btn-outline-secondary btn-sm" href="{{ route('pending.index', ['slug' => $order->pendingAccount->slug, 'direction' => $order->pendingAccount->direction]) }}">Gestisci sospeso</a>
        @endif
        @unless($order->paid_at || $order->receipt_voided_at || ($order->pendingAccount && $order->pendingAccount->state !== 'cancelled'))
            <x-ui.icon-button action="receive" label="Registra incasso" :data-dialog-open="'receipt-'.$order->slug" :aria-controls="'receipt-'.$order->slug" aria-haspopup="dialog" text />
        @endunless
        <details class="row-actions-menu"><summary class="icon-button" aria-label="Altre operazioni per {{ $order->reference }}" title="Altre operazioni"><x-ui.icon name="three-dots" /></summary><div class="row-actions-popover">
            <x-ui.icon-button :action="$order->receipt_voided_at ? 'restore' : 'delete'" :label="$order->receipt_voided_at ? 'Ripristina incasso' : 'Storna incasso'" :data-dialog-open="'receipt-state-'.$order->slug" :aria-controls="'receipt-state-'.$order->slug" aria-haspopup="dialog" text />
            <a class="btn btn-outline-secondary btn-sm" href="{{ route('audits.index', ['type' => 'orders', 'slug' => $order->slug]) }}">Storico dell’incasso</a>
        </div></details>
    @endif
@endif
