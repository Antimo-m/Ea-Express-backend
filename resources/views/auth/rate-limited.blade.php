<x-guest-layout title="Attendi prima di riprovare">
    <h1 class="auth-title">Una breve pausa.</h1>
    <p role="alert">{{ $message }}</p>
    <p class="text-secondary">Per proteggere il tuo account limitiamo le richieste ripetute. Attendi prima di inviare nuovamente il modulo.</p>
    <a class="btn btn-outline-primary" href="{{ route('login') }}">Torna all’accesso</a>
</x-guest-layout>
