<x-mail::message>
# {{ $event === 'delivered' ? 'Il tuo ordine è stato consegnato' : 'La tua spedizione è programmata' }}

@if($event === 'delivered')
La consegna è stata completata il **{{ $snapshot['delivered_at'] }}**.
@else
Abbiamo ricevuto la richiesta. Il corriere confermerà la presa in carico e gli orari.
@endif

**Ordine:** {{ $snapshot['reference'] }}

<x-mail::panel>
**Mittente:** {{ $snapshot['sender'] }}

**Destinatario:** {{ $snapshot['recipient'] }}

**Contenuto:** {{ $snapshot['content'] }}

**Ritiro:** {{ $snapshot['pickup'] }}

**Consegna:** {{ $snapshot['delivery'] }}

**Data ritiro:** {{ $snapshot['date'] }} · {{ $snapshot['pickup_time'] }}

@if($snapshot['delivery_time'])
**Preferenza oraria di consegna:** {{ $snapshot['delivery_time'] }}
@endif
</x-mail::panel>

<x-mail::button :url="$snapshot['tracking_url']">
Segui la spedizione
</x-mail::button>

Il collegamento è riservato a questa spedizione. Il tracking si attiva quando il rider parte per il ritiro.

Grazie,

**EA Express**
</x-mail::message>
