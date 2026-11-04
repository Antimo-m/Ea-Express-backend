<div class="register-table-wrap"><table class="table register-table accounting-table" data-table-static>
    <caption class="visually-hidden">{{ $caption }}</caption>
    <thead><tr><th scope="col">Cliente e ordine</th><th scope="col">Consegna</th><th scope="col" class="text-end">{{ match($mode) { 'recorded' => 'Incasso effettivo', 'reversed' => 'Tariffa esclusa', default => 'Da registrare' } }}</th><th scope="col">Stato</th><th scope="col">Operazioni</th></tr></thead>
    <tbody>@forelse($receipts as $order)<tr data-receipt="{{ $order->slug }}">
        <td><a class="register-primary" href="{{ route('orders.show', $order) }}">{{ $order->displayName() }}</a><span class="register-reference">{{ $order->reference }}</span></td>
        <td><time datetime="{{ $order->delivered_at->toIso8601String() }}">{{ $order->delivered_at->timezone('Europe/Rome')->format('d/m/Y') }}</time></td>
        <td class="text-end register-amount">{{ \App\Support\Money::format($mode === 'recorded' ? (int) $order->payments_sum_amount_cents : ($mode === 'reversed' ? $order->price_cents : $order->price_cents - (int) $order->payments_sum_cash_cents)) }}@if($mode === 'recorded')<small>Tariffa: {{ \App\Support\Money::format($order->price_cents) }}</small>@endif</td>
        <td>@if($order->receipt_voided_at)<span class="status-pill tone-danger">Stornato</span><small class="register-note">{{ $order->receipt_voided_at->timezone('Europe/Rome')->format('d/m/Y H:i') }} · {{ $order->receipt_void_reason }}</small>@elseif($order->pendingAccount && $order->pendingAccount->state !== 'cancelled')<x-ui.pending-status :account="$order->pendingAccount" />@else<span class="status-pill {{ $order->paid_at ? 'tone-green' : 'tone-orange' }}">{{ $order->paid_at ? 'Registrato' : 'Da registrare' }}</span>@endif</td>
        <td><div class="register-actions">@include('balance.receipt-actions')</div></td>
    </tr>@empty<tr><td colspan="5" class="register-empty">{{ $empty }}</td></tr>@endforelse</tbody>
</table></div>
{{ $receipts->links('components.ui.pagination', ['descriptive' => true, 'numbered' => true]) }}
