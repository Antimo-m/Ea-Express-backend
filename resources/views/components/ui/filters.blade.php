@props(['reset', 'label' => 'Filtra risultati'])
<form method="get" {{ $attributes->class(['filter-bar', 'surface']) }} aria-label="Filtri di ricerca">
    {{ $slot }}
    <div class="filter-actions">
        <x-ui.icon-button action="reset" :href="$reset" label="Azzera filtri" text />
        <x-ui.icon-button action="search" :label="$label" type="submit" />
    </div>
</form>
