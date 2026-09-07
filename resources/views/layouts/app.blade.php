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
        @if($errors->any())<div class="alert alert-danger" role="alert"><strong>Controlla i dati inseriti.</strong><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        {{ $slot }}
    </main>
</div>
<x-navigation.mobile />
</body>
</html>
