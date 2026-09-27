@props(['summary', 'receipts'])
<section class="surface workspace-section shipping-economics">
    <header class="section-heading"><div><span class="eyebrow">ECONOMIA DELLE CONSEGNE</span><h2>Ricavi, vettori e margine</h2></div></header>
    <p class="small text-secondary">Spedizioni consegnate nel periodo. Il margine sottrae il costo vettore salvato sull’ordine; non rappresenta utile netto né saldo di cassa. Le spese generali restano separate.</p>
    <div class="report-kpis">
        <article class="report-kpi"><span>Fuori regione · ricavi</span><strong>{{ \App\Support\Money::format($summary['external_revenue_cents']) }}</strong><small>{{ $summary['external_count'] }} consegne</small></article>
        <article class="report-kpi"><span>Costi vettori</span><strong>{{ \App\Support\Money::format($summary['carrier_cost_cents']) }}</strong><small>Costo previsto salvato nel listino</small></article>
        <article class="report-kpi"><span>Margine fuori regione</span><strong>{{ \App\Support\Money::format($summary['external_margin_cents']) }}</strong><small>Prezzo cliente − costo vettore</small></article>
        <article class="report-kpi"><span>Margine spedizioni totale</span><strong>{{ \App\Support\Money::format($summary['shipping_margin_cents']) }}</strong><small>Incluse {{ $summary['regional_count'] }} consegne regionali</small></article>
    </div>
</section>

<section class="surface p-4 my-4"><h2 class="h5">Incassi effettivi delle spedizioni</h2><p>Totale registrato: <strong>{{ \App\Support\Money::format($receipts['gross']) }}</strong></p><p class="small text-secondary">Pagamenti al netto degli storni. Le tariffe e i costi previsti sopra sono distinti dagli importi realmente incassati. I costi entrano nel saldo di cassa quando registrati come spesa.</p></section>
