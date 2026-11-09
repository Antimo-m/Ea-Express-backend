<div class="register-table-wrap finance-desktop-register"><table class="table register-table accounting-table" data-table-static>
    <caption class="visually-hidden">{{ $caption }}</caption>
    <thead><tr><th scope="col">Cliente e ordine</th><th scope="col">Consegna</th><th scope="col" class="text-end">{{ match($mode) { 'recorded' => 'Incasso effettivo', 'reversed' => 'Tariffa esclusa', default => 'Da registrare' } }}</th><th scope="col">Stato</th><th scope="col">Operazioni</th></tr></thead>
    <tbody>@forelse($receipts as $order)<tr data-receipt="{{ $order->slug }}">
        <td><a class="register-primary" href="{{ route('orders.show', $order) }}">{{ $order->displayName() }}</a><span class="register-reference">{{ $order->reference }}</span></td>
        <td><time datetime="{{ $order->delivered_at->toIso8601String() }}">{{ $order->delivered_at->timezone('Europe/Rome')->format('d/m/Y') }}</time></td>
        <td class="text-end register-amount">{{ \App\Support\Money::format($mode === 'recorded' ? (int) $order->payments_sum_amount_cents : ($mode === 'reversed' ? $order->price_cents : $order->price_cents - (int) $order->payments_sum_cash_cents)) }}@if($mode === 'recorded')<small>Tariffa: {{ \App\Support\Money::format($order->price_cents) }}</small>@endif</td>
        <td>@include('balance.receipt-status')</td>
        <td><div class="register-actions">@include('balance.receipt-actions')</div></td>
    </tr>@empty<tr><td colspan="5" class="register-empty">{{ $empty }}</td></tr>@endforelse</tbody>
</table></div>
<div class="finance-mobile-list" aria-label="{{ $caption }}">
@forelse($receipts as $order)
    <details class="cash-movement" data-cash-receipt="{{ $order->slug }}">
        <summary><span class="cash-movement-identity"><strong>{{ match($mode) { 'recorded' => 'Incasso registrato', 'reversed' => 'Incasso stornato', default => 'Da incassare' } }}</strong><span>{{ $order->reference }} · {{ $order->displayName() }}</span><time datetime="{{ $order->delivered_at->toIso8601String() }}">{{ $order->delivered_at->timezone('Europe/Rome')->format('d/m/Y — H:i') }}</time></span><span class="cash-movement-value">{{ \App\Support\Money::format($mode === 'recorded' ? (int) $order->payments_sum_amount_cents : ($mode === 'reversed' ? $order->price_cents : $order->price_cents - (int) $order->payments_sum_cash_cents)) }}<small>{{ match($mode) { 'recorded' => 'Entrata registrata', 'reversed' => 'Tariffa esclusa', default => 'Residuo da registrare' } }}</small></span></summary>
        <div class="cash-movement-detail"><dl>
            <div><dt>Cliente / mittente</dt><dd>{{ $order->displayName() }}</dd></div>
            <div><dt>Destinatario</dt><dd>{{ $order->recipient_name }}</dd></div>
            <div><dt>Tariffa originale</dt><dd>{{ \App\Support\Money::format($order->price_cents) }}</dd></div>
            <div><dt>Stato</dt><dd>@include('balance.receipt-status')</dd></div>
            @if($order->paid_at)<div><dt>Data incasso</dt><dd>{{ $order->paid_at->timezone('Europe/Rome')->format('d/m/Y H:i') }}</dd></div>@endif
        </dl><div class="register-actions"><a class="btn btn-outline-primary btn-sm" href="{{ route('orders.show', $order) }}">Apri ordine</a>@include('balance.receipt-actions')</div></div>
    </details>
@empty<p class="register-empty">{{ $empty }}</p>@endforelse
</div>
@foreach($receipts as $order)@include('balance.receipt-dialogs')@endforeach
{{ $receipts->links('components.ui.pagination', ['compact' => true]) }}
