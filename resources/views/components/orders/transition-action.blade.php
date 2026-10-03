@props(['order', 'status', 'text' => true])
@php($critical = in_array($status, [\App\OrderStatus::Cancelled, \App\OrderStatus::Rejected], true))
<x-ui.action-dialog :id="'workflow-'.$order->id.'-'.$status->value" :action="$critical ? 'delete' : 'operate'" :title="$status->actionLabel()" :open="$errors->any() && old('status') === $status->value" :text="$critical ? false : $text">
    <form method="post" action="{{ route('orders.update', $order) }}" class="form-stack">
        @csrf @method('patch')
        <input type="hidden" name="version" value="{{ $order->version }}">
        <input type="hidden" name="status" value="{{ $status->value }}">
        @if($status === \App\OrderStatus::Cancelled)
            <label class="form-label" for="cancellation-reason-{{ $order->id }}">Tipo di annullamento</label>
            <select class="form-select" id="cancellation-reason-{{ $order->id }}" name="cancellation_reason" required>
                <option value="other" @selected(old('cancellation_reason') === 'other')>Altro motivo · nessuna segnalazione destinatario</option>
                <option value="recipient_absent" @selected(old('cancellation_reason') === 'recipient_absent')>Destinatario assente / mancata consegna</option>
            </select>
            <p class="small text-secondary">Il motivo destinatario assente registra un precedente interno, solo dopo un tentativo di consegna.</p>
        @endif
        @if(in_array($status, [\App\OrderStatus::Cancelled, \App\OrderStatus::Rejected, \App\OrderStatus::DeliveryIssue, \App\OrderStatus::DeliveryAttempted], true))
            <x-ui.field :id="'note-'.$order->id.'-'.$status->value" name="note" label="Motivo" :value="old('status') === $status->value ? old('note') : ''" maxlength="2000" required />
        @endif
        <x-ui.icon-button type="submit" :action="$critical ? 'delete' : 'confirm'" :label="$status->actionLabel()" :data-confirm="$critical ? $status->actionLabel() : null" :data-confirm-name="$order->displayName()" text />
    </form>
</x-ui.action-dialog>
