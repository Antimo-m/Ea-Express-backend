{{-- Pagina neutra per accessi non consentiti e pause di sicurezza: autonoma (nessun file esterno), così funziona anche dietro il cancello di entrambi i domini. --}}
{{-- Con $blocked (cancello e pause) resta solo l'errore e, se esiste, si torna da soli alla pagina precedente dopo 8 secondi. --}}
@php($blocked = $blocked ?? false)
@php($title = $title ?? 'Pagina non disponibile')
@php($message = $message ?? ($blocked ? 'Non è possibile raggiungere la pagina richiesta.' : 'Non è possibile raggiungere la pagina richiesta. Torna alla pagina precedente oppure alla pagina iniziale.'))
@php($homeUrl = $homeUrl ?? route('dashboard'))
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#141a20">
<title>{{ $title }} · EA-Express</title>
<style>
:root { color-scheme: light dark; --bg: #f6f8f7; --surface: #fff; --ink: #141a20; --muted: #4b5a62; --line: #d9e2df; --signal: #e6a23d; --focus: #255774; }
@media (prefers-color-scheme: dark) { :root { --bg: #111b24; --surface: #1b2834; --ink: #f1f2ee; --muted: #b7c3c9; --line: #425563; --focus: #9ac9e2; } }
* { box-sizing: border-box; }
body { margin: 0; min-height: 100vh; min-height: 100dvh; display: grid; place-items: center; padding: 24px 16px; background: var(--bg); color: var(--ink); font: 16px/1.5 'Source Sans 3', 'Segoe UI', system-ui, sans-serif; }
main { width: min(100%, 440px); padding: 32px 28px; border: 1px solid var(--line); border-radius: 16px; background: var(--surface); box-shadow: 0 18px 40px -28px #141a2066; text-align: center; }
.brand { display: inline-flex; align-items: baseline; font: 600 21px/1.2 'Space Grotesk', 'Segoe UI', system-ui, sans-serif; letter-spacing: -.06em; }
.brand b { padding: 1px 4px; background: var(--signal); color: #141a20; font-weight: 700; }
.mark { width: 56px; height: 56px; margin: 24px auto 8px; display: grid; place-items: center; border-radius: 50%; background: color-mix(in srgb, var(--signal) 22%, transparent); }
.mark svg { width: 26px; height: 26px; }
h1 { margin: 8px 0; font: 600 1.4rem/1.25 'Space Grotesk', 'Segoe UI', system-ui, sans-serif; letter-spacing: -.02em; }
p { margin: 0 0 24px; color: var(--muted); }
.blocked p { margin-bottom: 0; }
.return { margin-top: 12px; font-size: .875rem; }
nav { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; }
a { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 44px; padding: 10px 18px; border-radius: 10px; font-weight: 600; text-decoration: none; }
a:focus-visible { outline: 3px solid var(--focus); outline-offset: 2px; }
.primary { background: var(--signal); color: #141a20; }
.secondary { border: 1px solid var(--line); color: var(--ink); }
@media (max-width: 380px) { main { padding: 28px 18px; } nav a { flex: 1 1 100%; } }
</style>
</head>
<body>
<main aria-labelledby="unavailable-title" @class(['blocked' => $blocked])>
    <span class="brand" role="img" aria-label="EA-Express"><b>EA</b><span>-Express</span></span>
    <div class="mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5"/><path d="M12 16.5h.01"/></svg></div>
    <h1 id="unavailable-title">{{ $title }}</h1>
    <p>{{ $message }}</p>
    @if($blocked)
        <p class="return" data-unavailable-return role="status" hidden>Tra <strong data-unavailable-seconds>8</strong> secondi tornerai alla pagina precedente.</p>
    @else
        <nav aria-label="Dove andare">
            <a class="secondary" href="{{ $homeUrl }}" data-unavailable-back>Torna indietro</a>
            <a class="primary" href="{{ $homeUrl }}">Pagina iniziale</a>
        </nav>
    @endif
</main>
<script @if(\Illuminate\Support\Facades\Vite::cspNonce()) nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" @endif>
(function () {
    var canGoBack = window.navigation ? window.navigation.canGoBack : history.length > 1;
    {{-- Torna indietro solo se esiste una pagina precedente; altrimenti il collegamento porta alla pagina iniziale. --}}
    document.querySelector('[data-unavailable-back]')?.addEventListener('click', function (event) {
        if (canGoBack) { event.preventDefault(); history.back(); }
    });
    var notice = document.querySelector('[data-unavailable-return]');
    if (!notice || !canGoBack) { return; }
    var seconds = 8;
    var counter = notice.querySelector('[data-unavailable-seconds]');
    notice.hidden = false;
    var timer = window.setInterval(function () {
        seconds -= 1;
        counter.textContent = String(seconds);
        if (seconds <= 0) { window.clearInterval(timer); history.back(); }
    }, 1000);
})();
</script>
</body>
</html>
