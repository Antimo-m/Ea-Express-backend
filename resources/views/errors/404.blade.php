<!DOCTYPE html>
<html lang="it">
<head><x-ui.head title="Error Not Found 404" /></head>
<body class="not-found-page">
    <main class="not-found-card" data-not-found data-home-url="{{ route('dashboard') }}" tabindex="-1">
        <x-application-logo />
        <h1>Error Not Found 404</h1>
        <p>Ci dispiace ma non è stato possibile trovare ciò che cercavi, verrai reindirizzato in home</p>
        <p role="status" aria-live="polite">Reindirizzamento tra <span data-not-found-countdown>5</span> secondi.</p>
        <a class="btn btn-primary" href="{{ route('dashboard') }}">Vai alla home</a>
    </main>
</body>
</html>
