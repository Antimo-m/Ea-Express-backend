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
    <div class="alert alert-success d-flex gap-2 align-items-start" role="status"><x-ui.icon name="check-circle" /><span>{{ $message }}</span></div>
@endif
