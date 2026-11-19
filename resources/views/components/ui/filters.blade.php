@props(['reset', 'label' => 'Applica filtri', 'compact' => true, 'accent' => null, 'layout' => 'data'])
{{-- Il pulsante «Filtri» nasce già visibile dove filter-toolbar.js lo mostrerà: niente salti al caricamento della pagina. --}}
@php($hasSecondary = isset($secondary))
@php($mobileTrigger = $hasSecondary || (in_array($layout, ['data', 'analysis'], true) && trim((string) $slot) !== ''))
<form method="get" {{ $attributes->class(['filter-bar', 'filter-toolbar', 'filters-orange' => $accent === 'orange', 'filter-toolbar--'.$layout]) }} data-filter-toolbar aria-label="Filtri di ricerca">
    @isset($search)<div class="filter-search"><x-ui.icon name="search" />{{ $search }}</div>@endisset
    @isset($period)<div class="filter-period" aria-label="Periodo">{{ $period }}</div>@endisset
    <div class="filter-primary" data-filter-primary>{{ $slot }}</div>
    @isset($secondary)
        <details class="filter-secondary" data-filter-secondary><summary class="btn btn-outline-secondary">Altri filtri</summary><div data-filter-secondary-fields>{{ $secondary }}</div></details>
    @endisset
    <div class="filter-actions">
        <button type="button" @class(['btn btn-outline-secondary filter-more', 'is-hidden-desktop' => ! $hasSecondary, 'is-hidden-mobile' => ! $mobileTrigger]) data-filter-more aria-expanded="false" aria-haspopup="dialog" aria-controls="filter-panel"><x-ui.icon name="sliders" /> <span data-filter-more-label>Filtri</span><span data-filter-count hidden></span></button>
        <x-ui.icon-button action="search" :label="$label" type="submit" class="filter-submit" />
        <x-ui.icon-button action="reset" :href="$reset" label="Ripristina filtri" class="filter-reset" />
    </div>
    <div class="active-filter-chips" data-filter-chips aria-label="Filtri applicati" hidden></div>
    <dialog id="filter-panel" class="filter-dialog" data-filter-dialog aria-label="Filtri">
        <header><strong data-filter-dialog-title>Filtri</strong><x-ui.icon-button icon="x-lg" label="Chiudi filtri" data-tooltip-placement="below-end" data-filter-close /></header>
        <div class="filter-dialog-fields" data-filter-dialog-fields></div>
        <footer><x-ui.icon-button action="reset" :href="$reset" label="Ripristina filtri" /><x-ui.icon-button action="search" type="submit" label="Applica filtri" /></footer>
    </dialog>
</form>
