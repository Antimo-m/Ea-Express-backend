@props(['reset', 'label' => 'Filtra risultati', 'compact' => false])
<form method="get" {{ $attributes->class(['filter-bar', 'surface' => ! $compact, 'filters-inline' => $compact]) }} aria-label="Filtri di ricerca">
    {{ $slot }}
    <div class="filter-actions">
        <x-ui.icon-button action="reset" :href="$reset" label="Azzera filtri" />
        <x-ui.icon-button action="search" :label="$label" type="submit" />
    </div>
</form>
