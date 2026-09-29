@props(['risk' => null])
@if($risk !== null)
    @if($risk['count'] > 0)
        <details class="mt-2"><summary><span class="status-pill tone-danger">NON AFFIDABILE</span></summary><small class="d-block">{{ $risk['count'] }} precedenti · Ultimo episodio: {{ \Illuminate\Support\Carbon::parse($risk['last_at'])->timezone('Europe/Rome')->format('d/m/Y') }}<br>Destinatario assente / mancata consegna. Segnalazione informativa: la spedizione può proseguire.</small></details>
    @else
        <span class="status-pill tone-green mt-2" title="Non risultano precedenti problemi registrati nel sistema.">AFFIDABILE</span><small class="d-block text-secondary">Nessun precedente registrato.</small>
    @endif
@endif
