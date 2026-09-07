<x-guest-layout title="Verifica email">
    <span class="eyebrow">UN ULTIMO PASSAGGIO</span><h2 class="auth-title">Controlla la tua email.</h2><p class="text-secondary mb-4">Apri il link che ti abbiamo inviato per verificare il tuo indirizzo. Se non lo trovi, puoi richiederne un altro.</p>
    <form method="post" action="{{ route('verification.send') }}">@csrf<button class="btn btn-primary w-100" type="submit">Invia un nuovo link</button></form>
    <form method="post" action="{{ route('logout') }}" class="mt-3">@csrf<button class="btn btn-light w-100" type="submit">Esci</button></form>
</x-guest-layout>
