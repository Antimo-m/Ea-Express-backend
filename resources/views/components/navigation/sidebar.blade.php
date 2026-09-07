<aside class="offcanvas-lg offcanvas-start app-sidebar" tabindex="-1" id="app-navigation" aria-labelledby="navigation-title">
    <div class="sidebar-brand"><a class="brand-link" href="{{ route('dashboard') }}"><x-application-logo /></a><button class="btn-close d-lg-none" type="button" data-bs-dismiss="offcanvas" data-bs-target="#app-navigation" aria-label="Chiudi menu"></button></div>
    <h2 id="navigation-title" class="visually-hidden">Menu principale</h2>
    <div class="offcanvas-body sidebar-content">
        <nav aria-label="Navigazione principale">
            @foreach (config('navigation') as $group => $items)
                <div class="nav-group"><p class="nav-group-label">{{ $group }}</p>@foreach ($items as $item)<x-navigation.link :item="$item" />@endforeach</div>
            @endforeach
        </nav>
        <div class="sidebar-foot"><span class="area-dot"></span> Al fianco della Campania<span class="d-block mt-1">EA-Express · Il tuo spazio operativo</span></div>
    </div>
</aside>
