<header class="simple-header"><div class="container d-flex align-items-center justify-content-between gap-3 flex-wrap py-3">
    <a class="brand-link" href="{{ route('dashboard') }}"><x-application-logo /></a>
    <nav class="d-flex gap-2 align-items-center flex-wrap" aria-label="Navigazione principale">
        <a class="btn btn-light" href="{{ route('dashboard') }}">Dashboard</a>
        <a class="btn btn-light" href="{{ route('profile.edit') }}">{{ auth()->user()->name }}</a>
        <form action="{{ route('logout') }}" method="post">@csrf<button class="btn btn-outline-secondary" type="submit">Esci</button></form>
    </nav>
</div></header>
