@if($order->receipt_voided_at)
    <span class="status-pill tone-danger">Stornato</span><small class="register-note">{{ $order->receipt_voided_at->timezone('Europe/Rome')->format('d/m/Y H:i') }} · {{ $order->receipt_void_reason }}</small>
@elseif($order->pendingAccount && $order->pendingAccount->state !== 'cancelled')
    <x-ui.pending-status :account="$order->pendingAccount" />
@else
    <span class="status-pill {{ $order->paid_at ? 'tone-green' : 'tone-orange' }}">{{ $order->paid_at ? 'Registrato' : 'Da registrare' }}</span>
@endif
