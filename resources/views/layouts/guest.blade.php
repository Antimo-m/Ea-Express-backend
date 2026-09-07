@props(['title' => 'Accedi'])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><x-ui.head :title="$title" /></head>
<body>
<a class="skip-link" href="#main-content">Vai al contenuto</a>
<div class="auth-shell">
    <aside class="auth-story" aria-label="EA-Express, consegne in Campania">
        <a href="{{ url('/') }}" class="brand-link"><x-application-logo /></a>
        <div class="auth-story-content"><span class="eyebrow">VICINI A TE. FINO ALLA CONSEGNA.</span><p class="story-heading">Ogni consegna,<br>un impegno<br><span>mantenuto.</span></p><p>Il tuo lavoro si muove.<br>EA-Express lo tiene in ordine.</p>
            <div class="route-illustration" aria-hidden="true"><span class="route-point"><x-ui.icon name="shop" /></span><span class="route-line"></span><span class="route-point route-point-brand"><x-ui.icon name="box-seam" /></span><span class="route-line"></span><span class="route-point"><x-ui.icon name="geo-alt" /></span></div>
        </div>
        <p class="small mb-0">Consegne locali. Persone, prima dei pacchi.</p>
    </aside>
    <main class="auth-main" id="main-content" tabindex="-1">
        <a href="{{ url('/') }}" class="brand-link auth-mobile-brand"><x-application-logo /></a>
        <div class="auth-card"><x-ui.flash />{{ $slot }}</div>
        <p class="auth-footer">EA-Express · Al fianco delle attività in Campania</p>
    </main>
</div>
</body>
</html>
