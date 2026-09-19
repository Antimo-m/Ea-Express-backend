@props(['order'])
@php($payment = $order->paymentData())
<section class="surface p-4 my-3" aria-label="Pagamento della spedizione">
    <h2 class="h5">Pagamento della spedizione</h2>
    <span class="status-pill tone-{{ $payment['state'] === 'paid' ? 'green' : ($payment['state'] === 'agreed' ? 'blue' : 'orange') }}">{{ $payment['label'] }}</span>
    <p class="mt-3 mb-1">Metodo: <strong>{{ $payment['method_label'] }}</strong></p>
    <p class="small text-secondary">La chat e l’accordo sul metodo non confermano l’incasso. Registra il pagamento ricevuto nel Bilancio dopo la consegna.</p>
    @if($order->payment_proposed_at)<p class="small text-secondary">Proposta del {{ $order->payment_proposed_at->timezone('Europe/Rome')->format('d/m/Y H:i') }} · {{ $order->payment_proposed_by === $order->customer_id ? 'Cliente' : 'Team EA Express' }}</p>@endif
    @if($order->payment_confirmed_at)<p class="small text-secondary">Confermato il {{ $order->payment_confirmed_at->timezone('Europe/Rome')->format('d/m/Y H:i') }}</p>@endif
    @if(!$order->paid_at && !in_array($order->status, [\App\OrderStatus::Cancelled, \App\OrderStatus::Rejected], true) && (auth()->user()->role === \App\UserRole::Admin || $order->rider_id === auth()->id()))
        @if($order->payment_method && !$order->payment_confirmed_at && $order->payment_proposed_by === $order->customer_id)
            <form method="post" action="{{ route('payment-agreement.update', $order) }}" class="mb-3">@csrf @method('patch')<input type="hidden" name="action" value="confirm"><input type="hidden" name="version" value="{{ $order->version }}"><button class="btn btn-primary">Conferma proposta del cliente</button></form>
        @endif
        <form method="post" action="{{ route('payment-agreement.update', $order) }}" class="form-stack">@csrf @method('patch')<input type="hidden" name="action" value="propose"><input type="hidden" name="version" value="{{ $order->version }}"><label for="agreement-method">Proponi un metodo</label><select class="form-select" id="agreement-method" name="method" required><option value="">Scegli</option>@foreach(\App\Support\PaymentMethod::Labels as $value => $label)<option value="{{ $value }}" @selected($order->payment_method === $value)>{{ $label }}</option>@endforeach</select><button class="btn btn-outline-primary">Invia proposta al cliente</button></form>
    @endif
</section>
