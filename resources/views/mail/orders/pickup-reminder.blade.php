<x-mail::message>
# {{ $kind === 'evening' ? 'Domani passiamo a ritirare' : 'Oggi passiamo a ritirare' }}

{{ ucfirst($snapshot['date']) }}: {{ count($snapshot['orders']) === 1 ? 'hai un ritiro programmato.' : 'hai '.count($snapshot['orders']).' ritiri programmati.' }} Prepara i pacchi chiusi ed etichettati prima dell’inizio della fascia oraria, così il rider non deve attendere.

@foreach($snapshot['orders'] as $order)
<x-mail::panel>
**{{ $order['reference'] }}**

**Fascia oraria:** {{ $order['time'] }}

**Ritiro:** {{ $order['pickup'] }}

**Destinatario:** {{ $order['recipient'] }}, {{ $order['destination'] }} · {{ $order['parcels'] }} {{ $order['parcels'] === 1 ? 'collo' : 'colli' }}
</x-mail::panel>
@endforeach

<x-mail::button :url="$portalUrl">
Apri i tuoi ritiri
</x-mail::button>

Se un ritiro non è più necessario o va spostato, scrivici dal portale prima dell’arrivo del rider.

_{{ \App\Support\MailLinkAdvice::Text }}_

Grazie,

**EA-Express**
</x-mail::message>
