@props(['name', 'risk' => null])
<span {{ $attributes->class(['order-recipient', 'is-unreliable' => ($risk['count'] ?? 0) > 0]) }}>
    <strong class="recipient-name">{{ $name }}</strong>
    @if($risk !== null)
        <span @class(['recipient-dot', 'recipient-dot--danger' => $risk['count'] > 0, 'recipient-dot--safe' => $risk['count'] === 0]) role="img" aria-label="{{ $risk['count'] > 0 ? 'Destinatario non affidabile: precedenti registrati' : 'Destinatario affidabile: nessun precedente registrato' }}" title="{{ $risk['count'] > 0 ? 'Destinatario non affidabile: precedenti registrati' : 'Nessun precedente registrato' }}"></span>
    @endif
</span>
