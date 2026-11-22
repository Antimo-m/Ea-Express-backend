<x-mail::message>
# Nuova segnalazione: {{ $category }}

**{{ $report['subject'] }}**

<x-mail::panel>
{!! nl2br(e($report['message'])) !!}
</x-mail::panel>

**Da:** {{ $reporter->name }} ({{ $reporter->email }})

**Provenienza:** {{ $source }}

@if(filled($report['location'] ?? null))
**Pagina o sezione:** {{ $report['location'] }}

@endif
**Inviata il:** {{ $sentAt }}

@if(count($files))
**Allegati:** {{ count($files) }} {{ count($files) === 1 ? 'file' : 'file' }} in questa email.

@endif
@if(filled($report['client'] ?? null))
_Dispositivo: {{ $report['client'] }}_

@endif
Rispondi a questa email per scrivere direttamente a chi ha inviato la segnalazione.

**EA-Express**
</x-mail::message>
