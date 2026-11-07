<div class="archive-day-content" data-history-day-content data-history-date="{{ $selectedDate }}" data-history-loaded="true">
    <p class="archive-page-caption">{{ $orders->total() }} {{ $orders->total() === 1 ? 'ordine' : 'ordini' }} nella giornata · Pagina {{ $orders->currentPage() }} di {{ $orders->lastPage() }}</p>
    <div class="history-day-orders">
        @forelse($orders as $order)<x-orders.row :order="$order" :recipient-risk="$recipientRisks[$order->id] ?? null" archive />
        @empty<p class="p-3">Nessun ordine per questa giornata e questi filtri.</p>@endforelse
    </div>
    {{ $orders->links('components.ui.pagination') }}
</div>
