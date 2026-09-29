<x-app-layout title="Destinatari non affidabili">
    <header class="page-heading"><div><span class="eyebrow">ARCHIVIO INTERNO</span><h1>Destinatari non affidabili</h1><p>Precedenti di mancata consegna per destinatario assente. Le segnalazioni non bloccano le spedizioni.</p></div></header>
    @forelse($profiles as $profile)
        <section class="surface p-4 mb-4">
            <h2 class="h5">{{ $profile->incidents->first()->recipient['recipient_name'] }}</h2>
            <p>{{ $profile->active_count }} precedenti attivi · {{ $profile->incidents->first()->recipient['recipient_phone'] }}</p>
            @foreach($profile->incidents as $incident)
                <details class="mb-3"><summary>{{ $incident->occurred_at->timezone('Europe/Rome')->format('d/m/Y H:i') }} · {{ $incident->order->reference }} · {{ $incident->dismissed_at ? 'Segnalazione rimossa' : 'Destinatario assente' }}</summary>
                    <p class="mt-2">{{ $incident->recipient['delivery_address'] }} {{ $incident->recipient['delivery_street_number'] ?? '' }} · {{ $incident->recipient['delivery_postal_code'] ?? '' }} {{ $incident->recipient['delivery_city'] }}</p>
                    <a href="{{ route('orders.show', $incident->order) }}">Apri ordine</a> · <a href="{{ route('audits.index', ['type' => 'recipient_incidents', 'id' => $incident->id]) }}">Storico rettifiche</a>
                    @if($incident->correction_reason)<p>Ultima rettifica: {{ $incident->correction_reason }}</p>@endif
                    @unless($incident->dismissed_at)
                        <form method="post" action="{{ route('recipient-incidents.update', $incident) }}" class="form-stack mt-3">@csrf @method('patch')
                            <input type="hidden" name="version" value="{{ $incident->version }}">
                            <div class="field-grid">
                                @foreach(['recipient_name'=>'Nome destinatario','recipient_phone'=>'Telefono','delivery_address'=>'Indirizzo','delivery_street_number'=>'Civico','delivery_postal_code'=>'CAP','delivery_city'=>'Comune','delivery_province'=>'Provincia'] as $field=>$label)
                                    <x-ui.field :id="$field.'-'.$incident->id" :name="$field" :label="$label" :value="$incident->recipient[$field] ?? ''" :required="$field !== 'delivery_province'" />
                                @endforeach
                            </div>
                            <x-ui.field :id="'reason-'.$incident->id" name="correction_reason" label="Motivo della rettifica / rimozione" maxlength="500" required />
                            <div class="row-actions"><button class="btn btn-primary" name="action" value="correct">Correggi dati del precedente</button><button class="btn btn-outline-danger" name="action" value="dismiss" formnovalidate data-confirm="Rimuovi segnalazione" data-confirm-name="{{ $incident->recipient['recipient_name'] }}">Rimuovi segnalazione</button></div>
                        </form>
                    @endunless
                </details>
            @endforeach
        </section>
    @empty
        <section class="surface p-4"><p>Nessun precedente registrato.</p></section>
    @endforelse
    {{ $profiles->links() }}
</x-app-layout>
