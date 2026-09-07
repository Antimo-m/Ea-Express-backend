<x-guest-layout title="Benvenuto">
    <span class="eyebrow">LA TUA PIATTAFORMA LOCALE</span><h1 class="auth-title">Meno pensieri.<br>Più consegne.</h1><p class="text-secondary mb-4">Uno spazio dedicato al lavoro quotidiano di EA-Express, vicino alle attività del territorio.</p>
    <div class="form-stack">
        @auth<a class="btn btn-primary" href="{{ route('dashboard') }}">Apri la dashboard <x-ui.icon name="arrow-right" class="ms-2" /></a>
        @else<a class="btn btn-primary" href="{{ route('login') }}">Accedi al tuo account <x-ui.icon name="arrow-right" class="ms-2" /></a>@if(config('access.registration'))<a class="btn btn-outline-secondary" href="{{ route('register') }}">Crea un account</a>@endif@endauth
    </div>
</x-guest-layout>
