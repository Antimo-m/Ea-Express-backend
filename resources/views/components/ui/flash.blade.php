@php
    $status = session('status');
    $message = match ($status) {
        'profile-updated' => 'Profilo aggiornato.',
        'password-updated' => 'Password aggiornata.',
        'verification-link-sent' => 'Ti abbiamo inviato un nuovo link di verifica.',
        default => $status,
    };
@endphp
@if ($message)
    <div data-toast class="alert alert-success d-flex gap-2 align-items-start" role="status"><x-ui.icon name="check-circle" /><span class="flex-grow-1">{{ $message }}</span><button type="button" class="btn-close" data-toast-dismiss aria-label="Chiudi messaggio"></button></div>
@endif
