<x-app-layout title="Rider">
    <header class="page-heading"><div><span class="eyebrow">IL TEAM SUL TERRITORIO</span><h1>Rider</h1><p>Persone, carichi di lavoro e accessi del tuo team.</p></div><a class="btn btn-primary" href="{{ route('users.create') }}">Aggiungi</a></header>
    @if($users->isNotEmpty())
    <x-ui.directory label="Team Rider" search-label="Cerca nome o email in questa pagina" class="rider-directory">
        <x-slot:summary><p>{{ $users->total() }} account Rider</p></x-slot:summary>
        <x-slot:list>
            @foreach($users as $rider)
            <a class="directory-person" data-directory-select="{{ $rider->slug }}" data-directory-name="{{ $rider->name }} {{ $rider->email }}" href="{{ route('rider-operations.show', $rider) }}" @if($loop->first) aria-current="true" @endif aria-controls="rider-{{ $rider->slug }}">
                <x-ui.identity-avatar :name="$rider->name" />
                <span class="directory-person-copy"><strong>{{ $rider->name }}</strong><span class="directory-person-state"><i @class(['presence-dot', 'is-active' => $rider->is_active]) aria-hidden="true"></i>{{ $rider->is_active ? 'Account attivo' : 'Account disabilitato' }}</span><small>{{ $rider->active_orders_count }} spedizioni aperte</small></span><x-ui.icon name="chevron-right" />
            </a>
            @endforeach
        </x-slot:list>
        <x-slot:pagination>{{ $users->links('components.ui.pagination') }}</x-slot:pagination>
        @foreach($users as $rider)
        <section class="directory-detail rider-profile" id="rider-{{ $rider->slug }}" data-directory-panel="{{ $rider->slug }}" @if(!$loop->first) hidden @endif aria-labelledby="rider-heading-{{ $rider->slug }}">
            <header class="profile-heading"><div class="profile-identity"><x-ui.identity-avatar :name="$rider->name" class="identity-avatar-lg" /><div><span class="eyebrow">PROFILO RIDER</span><h2 id="rider-heading-{{ $rider->slug }}" tabindex="-1" data-directory-heading>{{ $rider->name }}</h2><p>{{ $rider->email }}</p></div></div><x-ui.status-badge :tone="$rider->is_active ? 'green' : 'danger'" :label="$rider->is_active ? 'Account attivo' : 'Disabilitato'" /></header>
            <dl class="profile-metrics" aria-label="Spedizioni attualmente assegnate"><div><dt>Da completare</dt><dd>{{ $rider->active_orders_count }}</dd></div><div><dt>Consegnate · totale</dt><dd>{{ $rider->delivered_count }}</dd></div><div><dt>Assegnate · totale</dt><dd>{{ $rider->assigned_count }}</dd></div></dl>
            <section class="profile-section"><header><h3>Destinazioni delle spedizioni aperte</h3><x-ui.icon name="geo-alt" /></header>
                <ul class="destination-list">@forelse($destinations->get($rider->id, collect()) as $destination)<li><span>{{ $destination->delivery_city ?: 'Località non indicata' }}</span><strong>{{ $destination->total }} <small>{{ (int) $destination->total === 1 ? 'spedizione' : 'spedizioni' }}</small></strong></li>@empty<li class="profile-empty">Nessuna spedizione aperta assegnata.</li>@endforelse</ul>
            </section>
            <section class="profile-section profile-access"><header><h3>Accesso al gestionale</h3><x-ui.icon name="shield-check" /></header><p>{{ $rider->email_verified_at ? 'Email verificata' : 'Email da verificare al primo accesso' }}</p>
            @can('update', $rider)<x-ui.action-dialog :id="'user-access-'.$rider->slug" title="Gestisci accesso" icon="shield-lock" :description="$rider->name" text><form method="post" action="{{ route('users.update', $rider) }}" class="form-stack mt-3">@csrf @method('patch')<p>La modifica disconnette il rider dalle sessioni precedenti.</p><div class="row-actions"><x-ui.icon-button type="submit" :action="$rider->is_active ? 'delete' : 'edit'" :label="$rider->is_active ? 'Disabilita account' : 'Riattiva account'" name="action" :value="$rider->is_active ? 'deactivate' : 'activate'" :data-confirm="$rider->is_active ? 'Disabilita account' : null" :data-confirm-name="$rider->name" text /><x-ui.icon-button type="submit" action="delete" label="Revoca dispositivi e sessioni" name="action" value="revoke-devices" data-confirm="Revoca dispositivi e sessioni" :data-confirm-name="$rider->name" text />@if($rider->email_verified_at)<x-ui.icon-button type="submit" action="confirm" label="Ripristina verifica email" name="action" value="reset-email" data-confirm="Ripristina verifica email" :data-confirm-name="$rider->name" text />@endif</div></form></x-ui.action-dialog>@endcan
            </section>
            <footer class="profile-footer"><button type="button" class="btn btn-outline-secondary directory-return" data-directory-return><x-ui.icon name="arrow-up" /> Elenco Rider</button><a class="btn btn-primary" href="{{ route('rider-operations.show', $rider) }}"><x-ui.icon name="calendar3" /> Dettaglio giornaliero</a></footer>
        </section>
        @endforeach
    </x-ui.directory>
    @else<section class="surface empty-state"><x-ui.icon name="people" /><h2>Il team parte da qui</h2><p>Crea il primo account Rider per assegnare le richieste.</p><a class="btn btn-primary" href="{{ route('users.create') }}">Aggiungi</a></section>@endif
</x-app-layout>
