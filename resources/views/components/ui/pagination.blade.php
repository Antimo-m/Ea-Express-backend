@props(['paginator', 'descriptive' => false, 'numbered' => false, 'compact' => false])
@if($paginator->hasPages())
<nav class="workspace-pagination rates-pagination" aria-label="Paginazione risultati" data-pagination-page="{{ $paginator->currentPage() }}" data-pagination-last="{{ method_exists($paginator, 'lastPage') ? $paginator->lastPage() : $paginator->currentPage() }}" data-pagination-name="{{ $paginator->getPageName() }}">
    @if($paginator->onFirstPage())
        <button type="button" class="page-arrow" disabled aria-label="Pagina precedente" title="Pagina precedente" data-tooltip="Pagina precedente"><x-ui.icon name="arrow-left" /></button>
    @else
        <a class="page-arrow" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Pagina precedente" title="Pagina precedente" data-tooltip="Pagina precedente"><x-ui.icon name="arrow-left" /></a>
    @endif
    @if($numbered && ! $compact)
        <div class="pagination-pages">@foreach($elements as $element)
            @if(is_string($element))<span class="pagination-ellipsis" aria-hidden="true">{{ $element }}</span>
            @else @foreach($element as $page => $url)
                @if($page === $paginator->currentPage())<span class="page-number is-current" aria-current="page" aria-label="Pagina {{ $page }}">{{ $page }}</span>
                @else<a class="page-number" href="{{ $url }}" aria-label="Vai a pagina {{ $page }}">{{ $page }}</a>@endif
            @endforeach @endif
        @endforeach</div>
    @endif
    <span aria-live="polite" aria-current="page" aria-label="Pagina {{ $paginator->currentPage() }}">{{ ! $compact && $descriptive ? 'Pagina ' : '' }}{{ $paginator->currentPage() }}@if(! $compact && method_exists($paginator, 'lastPage')){{ $descriptive ? ' di ' : ' / ' }}{{ $paginator->lastPage() }}@endif</span>
    @if($paginator->hasMorePages())
        <a class="page-arrow" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Pagina successiva" title="Pagina successiva" data-tooltip="Pagina successiva"><x-ui.icon name="arrow-right" /></a>
    @else
        <button type="button" class="page-arrow" disabled aria-label="Pagina successiva" title="Pagina successiva" data-tooltip="Pagina successiva"><x-ui.icon name="arrow-right" /></button>
    @endif
</nav>
@endif
