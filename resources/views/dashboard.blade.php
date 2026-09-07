<x-app-layout title="Dashboard">
    <x-slot name="header"><span class="eyebrow">IL TUO SPAZIO OPERATIVO</span><h1>Dashboard</h1></x-slot>
    <section class="surface p-4"><h2 class="h4">Benvenuto, {{ auth()->user()->name }}</h2><p class="text-secondary mb-0">Il tuo account è pronto. La dashboard operativa sarà disponibile a breve.</p></section>
</x-app-layout>
