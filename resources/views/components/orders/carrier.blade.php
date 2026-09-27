@props(['order'])
@if($order->shipping_type === 'external')
    @can('update', $order)
    @if(!in_array($order->status->value, \App\OrderStatus::closed(), true))
    <form action="{{ route('orders.carrier', $order) }}" method="post" class="form-stack">@csrf @method('patch')<input type="hidden" name="version" value="{{ $order->version }}">
        <x-ui.field name="carrier_name" label="Vettore" :value="old('carrier_name', $order->carrier_name)" maxlength="100" required/>
        <x-ui.field name="carrier_tracking" label="Codice tracking del vettore" :value="old('carrier_tracking', $order->carrier_tracking)" maxlength="100" required/>
        <div><label for="carrier_status" class="form-label">Stato comunicato dal vettore</label><select class="form-select" id="carrier_status" name="carrier_status">@foreach(['booked'=>'Prenotata','handed_over'=>'Affidata al vettore','in_transit'=>'In transito','delivery_issue'=>'Problema di consegna'] as $value=>$label)<option value="{{ $value }}" @selected(old('carrier_status',$order->carrier_status)===$value)>{{ $label }}</option>@endforeach</select></div>
        <x-ui.field name="estimated_at" label="Data e ora stimate dal vettore (facoltative)" type="datetime-local" :value="old('estimated_at', $order->estimated_at?->timezone('Europe/Rome')->format('Y-m-d\TH:i'))"/>
        <p class="small text-secondary">Tracking e aggiornamenti del vettore sono facoltativi. Avanza la spedizione con i consueti pulsanti del flusso.</p>
        <x-ui.icon-button action="edit" label="Aggiorna vettore" type="submit" text/>
    </form>
    @endif
    @endcan

@endif
