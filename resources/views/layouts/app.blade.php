@props(['title' => 'Dashboard'])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><x-ui.head :title="$title" /></head>
<body>
<a class="skip-link" href="#main-content">Vai al contenuto</a>
<x-navigation.sidebar />
<div class="app-workspace">
    <x-navigation.header :title="$title" />
    <main class="app-content" id="main-content" tabindex="-1">
        @isset($header)<header class="page-heading mb-4">{{ $header }}</header>@endisset
        <x-ui.flash />
        {{ $slot }}
    </main>
</div>
<x-navigation.mobile />
</body>
</html>
