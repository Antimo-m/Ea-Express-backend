@if($paginator->hasPages())
<nav class="workspace-pagination rates-pagination" aria-label="Paginazione risultati">
    @if($paginator->onFirstPage())
        <button type="button" class="page-arrow" disabled aria-label="Pagina precedente" title="Pagina precedente"><x-ui.icon name="arrow-left" /></button>
    @else
        <a class="page-arrow" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Pagina precedente" title="Pagina precedente"><x-ui.icon name="arrow-left" /></a>
    @endif
    <span aria-live="polite">{{ $paginator->currentPage() }}@if(method_exists($paginator, 'lastPage')) / {{ $paginator->lastPage() }}@endif</span>
    @if($paginator->hasMorePages())
        <a class="page-arrow" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Pagina successiva" title="Pagina successiva"><x-ui.icon name="arrow-right" /></a>
    @else
        <button type="button" class="page-arrow" disabled aria-label="Pagina successiva" title="Pagina successiva"><x-ui.icon name="arrow-right" /></button>
    @endif
</nav>
@endif
