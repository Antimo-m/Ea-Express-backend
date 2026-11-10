<x-app-layout title="Il mio profilo">
    <x-slot name="header"><span class="eyebrow">IL TUO ACCOUNT</span><h1>Il mio profilo</h1><p class="text-secondary mb-0">Gestisci i tuoi dati e mantieni al sicuro l'accesso.</p></x-slot>
    <div class="profile-grid" data-workspace-region="profile">
        <section class="surface p-4">@include('profile.partials.update-profile-information-form')</section>
        <section class="surface p-4">@include('profile.partials.update-password-form')</section>
        <section class="surface p-4"><h2 class="h5">Gestione e chiusura account</h2><p>Per disattivare l’account o gestire una richiesta relativa ai tuoi dati personali, contatta l’Admin. Lo storico operativo e contabile viene conservato.</p></section>
    </div>
</x-app-layout>
