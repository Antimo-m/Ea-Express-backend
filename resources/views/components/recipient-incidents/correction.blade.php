@props(['incident', 'id' => null])
@php($id ??= 'incident-correction-'.$incident->id)
<x-ui.action-dialog :id="$id" title="Modifica dati o rimuovi segnalazione" :description="$incident->recipient['recipient_name'].' · Rettifica di questo episodio; i dati storici dell’ordine restano invariati.'" :open="$errors->any() && old('dialog_id') === $id">
    <form method="post" action="{{ route('recipient-incidents.update', $incident) }}" class="form-stack">
        @csrf @method('patch')
        <input type="hidden" name="version" value="{{ $incident->version }}">
        <input type="hidden" name="dialog_id" value="{{ $id }}">
        <div class="field-grid">
            @foreach(['recipient_name'=>'Nome e cognome destinatario','recipient_phone'=>'Telefono','delivery_address'=>'Indirizzo','delivery_street_number'=>'Civico','delivery_postal_code'=>'CAP','delivery_city'=>'Località','delivery_province'=>'Provincia'] as $field=>$label)
                <x-ui.field :id="$id.'-'.$field" :name="$field" :label="$label" :value="old('dialog_id') === $id ? old($field, $incident->recipient[$field] ?? '') : ($incident->recipient[$field] ?? '')" :required="$field !== 'delivery_province'" />
            @endforeach
        </div>
        <x-ui.field :id="$id.'-reason'" name="correction_reason" label="Motivo della rettifica / rimozione" :value="old('dialog_id') === $id ? old('correction_reason') : ''" maxlength="500" required />
        <footer class="modal-actions">
            <button type="button" class="btn btn-outline-secondary" data-dialog-close>Torna indietro</button>
            <x-ui.icon-button type="submit" action="confirm" label="Salva rettifica" name="action" value="correct" text />
            <x-ui.icon-button type="submit" action="delete" label="Rimuovi segnalazione" name="action" value="dismiss" formnovalidate data-confirm="Rimuovi segnalazione" :data-confirm-name="$incident->recipient['recipient_name']" text />
        </footer>
    </form>
</x-ui.action-dialog>
