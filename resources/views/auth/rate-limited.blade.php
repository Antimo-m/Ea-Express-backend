<x-guest-layout title="Attendi prima di riprovare">
    <h1 class="auth-title">Una breve pausa.</h1>
    <p role="alert">{{ $message }}</p>
    <p class="text-secondary">Per proteggere il tuo account limitiamo le richieste ripetute. Attendi prima di inviare nuovamente il modulo.</p>
    @if(session('staff_authentication.state') === \App\StaffAuthenticationState::PrimaryAuthenticated->value)
        <x-ui.icon-button action="back" :href="route('email-otp.notice')" label="Torna alla verifica" text />
    @else
        <x-ui.icon-button action="back" :href="route('login')" label="Torna all’accesso" text />
    @endif
</x-guest-layout>
