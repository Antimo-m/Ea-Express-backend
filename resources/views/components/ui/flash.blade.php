@php
    $status = session('status');
    $signedIn = $status === 'signed-in';
    $message = match ($status) {
        'signed-in' => 'Accesso eseguito',
        'profile-updated' => 'Profilo aggiornato.',
        'password-updated' => 'Password aggiornata.',
        'verification-link-sent' => 'Ti abbiamo inviato un nuovo link di verifica.',
        default => $status,
    };
@endphp
@if ($message)
    <div data-toast @class(['alert alert-success app-flash', 'app-flash-signed-in' => $signedIn]) role="status">
        <span class="app-flash-icon" aria-hidden="true"><x-ui.icon :name="$signedIn ? 'shield-check' : 'check-circle'" /></span>
        <span class="app-flash-text">
            <strong>{{ $message }}</strong>
            @if ($signedIn && auth()->check())<small>Ciao {{ \Illuminate\Support\Str::before(auth()->user()->name, ' ') }}, buon lavoro.</small>@endif
        </span>
        <button type="button" class="btn-close" data-toast-dismiss aria-label="Chiudi messaggio"></button>
    </div>
@endif
