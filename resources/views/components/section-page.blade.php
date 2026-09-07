@props(['title', 'icon', 'description', 'features'])
<x-app-layout :title="$title">
    <x-slot name="header"><span class="eyebrow">IL TUO GESTIONALE</span><h1>{{ $title }}</h1><p class="text-secondary mb-0">{{ $description }}</p></x-slot>
    <section class="surface section-preview" aria-labelledby="section-state-title">
        <div class="section-preview-intro"><div class="preview-symbol"><x-ui.icon :name="$icon" /><span class="preview-spark"><x-ui.icon name="plus" /></span></div><span class="status-pill tone-orange">Disponibile prossimamente</span><h2 id="section-state-title">Uno spazio per {{ mb_strtolower($title) }}.</h2><p>Questa sezione è in preparazione.<br>Le funzioni elencate non sono ancora attive.</p><a class="btn btn-primary" href="{{ route('dashboard') }}"><x-ui.icon name="arrow-left" class="me-2" />Torna alla dashboard</a></div>
        <div class="section-preview-features"><span class="eyebrow">COSA TROVERAI QUI</span><ul>@foreach($features as $feature)<li><span class="feature-dot"><x-ui.icon name="arrow-up-right" /></span><span>{{ $feature }}</span></li>@endforeach</ul>@if(request()->routeIs('settings.index'))<a class="back-link" href="{{ route('profile.edit') }}">Apri il mio profilo <x-ui.icon name="arrow-right" /></a>@endif</div>
    </section>
</x-app-layout>
