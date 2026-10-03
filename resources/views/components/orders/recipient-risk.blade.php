@props(['risk' => null])
@if($risk !== null)
    <div class="recipient-risk">
        @if($risk['count'] > 0)
            <x-ui.status-badge tone="danger" class="recipient-status recipient-status-danger" label="NON AFFIDABILE" />
            @if($risk['last_at'] ?? null)<small>Ultimo episodio: {{ \Illuminate\Support\Carbon::parse($risk['last_at'])->timezone('Europe/Rome')->format('d/m/Y') }}</small>@endif
        @else
            <x-ui.status-badge tone="green" class="recipient-status recipient-status-safe" title="Non risultano precedenti problemi registrati nel sistema." label="AFFIDABILE" />
            <small class="text-secondary">Nessun precedente registrato.</small>
        @endif
    </div>
@endif
