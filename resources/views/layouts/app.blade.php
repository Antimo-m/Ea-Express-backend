@props(['title' => 'Dashboard'])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><x-ui.head :title="$title" /></head>
<body>
<a class="skip-link" href="#main-content">Vai al contenuto</a>
@include('layouts.navigation')
<main class="container py-4 py-lg-5" id="main-content" tabindex="-1">
    @isset($header)<header class="mb-4">{{ $header }}</header>@endisset
    <x-ui.flash />
    {{ $slot }}
</main>
</body>
</html>
