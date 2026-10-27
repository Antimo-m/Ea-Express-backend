<x-app-layout title="Il mio profilo">
    <x-slot name="header"><span class="eyebrow">IL TUO ACCOUNT</span><h1>Il mio profilo</h1><p class="text-secondary mb-0">Gestisci i tuoi dati e mantieni al sicuro l'accesso.</p></x-slot>
    <div class="profile-grid">
        <section class="surface p-4">@include('profile.partials.update-profile-information-form')</section>
        <section class="surface p-4">@include('profile.partials.update-password-form')</section>
        <details class="surface p-4 profile-danger" @if($errors->userDeletion->isNotEmpty()) open @endif><summary>Gestione e chiusura account</summary><div class="mt-4">@include('profile.partials.delete-user-form')</div></details>
    </div>
</x-app-layout>
