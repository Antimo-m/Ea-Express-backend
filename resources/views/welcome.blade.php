<x-guest-layout title="Benvenuto">
    <span class="eyebrow">GESTIONALE EA-EXPRESS</span><h1 class="auth-title">Meno pensieri.<br>Più consegne.</h1><p class="text-secondary mb-4">Ritiri, spedizioni e consegne: un unico spazio per organizzare il lavoro della tua squadra.</p>
    <ul class="auth-features"><li><x-ui.icon name="box-seam" /> Ordini e ritiri sempre sotto controllo</li><li><x-ui.icon name="geo-alt" /> Stato delle consegne e tracking</li><li><x-ui.icon name="people" /> Uno spazio per Admin e Rider</li></ul>
    <div class="form-stack">
        @auth<a class="btn btn-primary" href="{{ route('dashboard') }}">Apri la dashboard <x-ui.icon name="arrow-right" class="ms-2" /></a>
        @else<a class="btn btn-primary" href="{{ route('login') }}">Accedi al tuo account <x-ui.icon name="arrow-right" class="ms-2" /></a>
        @endauth
    </div>
</x-guest-layout>
