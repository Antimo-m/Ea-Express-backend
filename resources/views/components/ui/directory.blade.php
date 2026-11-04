@props(['label', 'searchLabel' => 'Cerca in questa pagina'])
<section {{ $attributes->class(['directory-workspace']) }} data-directory data-workspace-region="directory" aria-label="{{ $label }}">
    <aside class="directory-rail" aria-label="{{ $label }} · elenco">
        <header class="directory-rail-heading"><div><span class="eyebrow">{{ $label }}</span>{{ $summary ?? '' }}</div></header>
        <label class="directory-search"><x-ui.icon name="search" /><input type="search" data-directory-search placeholder="Cerca in questa pagina" aria-label="{{ $searchLabel }}"></label>
        <p class="directory-search-empty" data-directory-empty hidden role="status">Nessun risultato in questa pagina.</p>
        <div class="directory-list" data-directory-list>{{ $list }}</div>
        @isset($pagination)<footer class="directory-pagination">{{ $pagination }}</footer>@endisset
    </aside>
    <div class="directory-details" data-directory-details><section class="directory-detail directory-no-selection" data-directory-no-selection hidden><x-ui.icon name="search" /><h2>Nessuna corrispondenza</h2><p>Modifica la ricerca per tornare ai dettagli.</p></section>{{ $slot }}</div>
</section>
