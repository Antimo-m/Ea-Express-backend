@props(['title' => 'Dashboard'])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><x-ui.head :title="$title" /></head>
<body class="ea-app" data-notifications-url="{{ route('notifications.feed') }}" data-notification-scope="staff:{{ auth()->id() }}">
<a class="skip-link" href="#main-content">Vai al contenuto</a>
<x-navigation.sidebar />
<div class="app-workspace">
    <x-navigation.header :title="$title" />
    @if(auth()->user()->canOperateDeliveries())
    <div class="gps-bar" data-rider-gps="{{ auth()->id() }}" data-gps-state="disabled">
        <span class="gps-badge" data-gps-badge role="status" aria-label="GPS disattivato" title="GPS disattivato · apri una consegna per condividere la posizione."><span class="gps-live-dot" aria-hidden="true"></span>GPS</span>
        <span class="visually-hidden" data-gps-feedback>GPS disattivato · apri una consegna per condividere la posizione.</span>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-gps-stop hidden>Interrompi GPS</button>
    </div>
    @endif
    <main class="app-content" id="main-content" tabindex="-1">
        @isset($header)<header class="page-heading mb-4">{{ $header }}</header>@endisset
        <x-ui.flash />
        @if($errors->any())<div data-toast class="alert alert-danger" role="alert"><strong>Controlla i dati inseriti.</strong><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        {{ $slot }}
    </main>
</div>
<x-navigation.mobile />
<x-ui.modal id="action-confirmation" title="Conferma operazione" danger>
    <p class="confirmation-summary" data-confirm-summary></p>
    <p class="confirmation-summary" data-confirm-description></p>
    <p role="alert" data-modal-error></p>
    <footer class="modal-actions"><button type="button" class="btn modal-back" data-dialog-close><x-ui.icon name="arrow-left"/> Torna indietro</button><button type="button" class="btn btn-danger" data-confirm-action><x-ui.icon name="trash"/><span data-confirm-label>Elimina</span></button></footer>
</x-ui.modal>
</body>
</html>
