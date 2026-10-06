<x-app-layout title="Clienti">
    <header class="page-heading"><div><span class="eyebrow">RELAZIONI CHE GENERANO VALORE</span><h1>Clienti</h1><p>Ogni account, il suo lavoro e il valore per EA-Express.</p></div><span class="count-badge">{{ $customers->total() }} clienti</span></header>
    <x-ui.filters :reset="route('customers.index')">
        <x-slot:search><x-ui.field name="q" label="Cerca cliente" :value="request('q')" placeholder="Cerca cliente, negozio o email…" /></x-slot:search>
        <x-ui.filter-select name="sender_type" label="Tipo account" :options="[''=>'Tutti','business'=>'Attività commerciale','private'=>'Privato','online_shop'=>'Shop online']" size="md" />
        <x-slot:secondary><x-ui.filter-select name="sort" label="Ordina per" default="name" :options="['name'=>'Nome','volume'=>'Spedizioni','value'=>'Valore spedizioni']" size="md" /></x-slot:secondary>
    </x-ui.filters>
    <div class="customer-directory">
        @forelse($customers as $customer)
        <a class="surface customer-entry" href="{{ route('customers.show', $customer) }}">
            <span class="customer-avatar"><x-ui.icon name="shop" /></span>
            <div class="customer-entry-identity"><span class="eyebrow">{{ ['business'=>'Attività commerciale','private'=>'Privato','online_shop'=>'Shop online'][$customer->sender_type] ?? 'Cliente' }}</span><h2>{{ $customer->name }}</h2><p>{{ $customer->email }}</p></div>
            <div><strong>{{ $customer->orders_count }}</strong><small>spedizioni totali</small></div>
            <div><strong>{{ \App\Support\Money::format((int) $customer->shipping_cents) }}</strong><small>valore spedizioni</small></div>
            <x-ui.icon name="arrow-up-right" />
        </a>
        @empty<div class="surface empty-state"><x-ui.icon name="people" /><h2>Nessun cliente trovato</h2><p>Gli account compariranno anche prima della prima spedizione.</p></div>@endforelse
    </div>
    {{ $customers->links('components.ui.pagination') }}
</x-app-layout>
