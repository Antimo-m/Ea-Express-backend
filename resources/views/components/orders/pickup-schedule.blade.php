@props(['order'])
<x-ui.action-dialog :id="'pickup-schedule-'.$order->id" action="edit" title="Ripianifica ritiro" :description="$order->reference" :open="$errors->hasAny(['pickup_date','pickup_from','pickup_to','reason'])" text>
    <form method="post" action="{{ route('orders.pickup-schedule',$order) }}" class="form-stack">@csrf @method('patch')
        <input type="hidden" name="version" value="{{ $order->version }}">
        <p class="small text-secondary">Data attuale: {{ $order->pickup_date->format('d/m/Y') }} · {{ substr($order->pickup_from,0,5) }}–{{ substr($order->pickup_to,0,5) }}. La modifica sarà visibile al cliente e conservata nello storico.</p>
        <x-ui.field name="pickup_date" label="Nuova data di ritiro" type="date" :value="old('pickup_date',$order->pickup_date->toDateString())" :min="now('Europe/Rome')->toDateString()" required />
        <div class="field-grid"><x-ui.field name="pickup_from" label="Dalle" type="time" :value="old('pickup_from',substr($order->pickup_from,0,5))" required /><x-ui.field name="pickup_to" label="Alle" type="time" :value="old('pickup_to',substr($order->pickup_to,0,5))" required /></div>
        <x-ui.field name="reason" label="Motivo della ripianificazione" :value="old('reason')" maxlength="500" required />
        <x-ui.icon-button action="edit" type="submit" label="Conferma nuovi orari" text />
    </form>
</x-ui.action-dialog>
