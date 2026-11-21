<x-app-layout title="Rider">
    <header class="page-heading"><div><span class="eyebrow">IL TEAM SUL TERRITORIO</span><h1>Rider</h1><p>Persone, carichi di lavoro e accessi del tuo team.</p></div><x-ui.icon-button action="add" :href="route('users.create')" label="Aggiungi Rider" class="users-add" /></header>
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
            @if(!$rider->is_active && $rider->deactivated_at)<p class="account-retention-note" role="note"><x-ui.icon name="hourglass-split" /> Disabilitato il {{ $rider->deactivated_at->timezone('Europe/Rome')->format('d/m/Y') }}. Senza riattivazione entra nella revisione di conservazione dal {{ \App\Support\DeactivatedAccounts::reviewDate($rider)->timezone('Europe/Rome')->format('d/m/Y') }}; ordini e contabilità restano conservati.</p>@endif
            <dl class="profile-metrics" aria-label="Spedizioni attualmente assegnate"><div><dt>Da completare</dt><dd>{{ $rider->active_orders_count }}</dd></div><div><dt>Consegnate · totale</dt><dd>{{ $rider->delivered_count }}</dd></div><div><dt>Assegnate · totale</dt><dd>{{ $rider->assigned_count }}</dd></div></dl>
            <section class="profile-section"><header><h3>Destinazioni delle spedizioni aperte</h3><x-ui.icon name="geo-alt" /></header>
                <ul class="destination-list">@forelse($destinations->get($rider->id, collect()) as $destination)<li><span>{{ $destination->delivery_city ?: 'Località non indicata' }}</span><strong>{{ $destination->total }} <small>{{ (int) $destination->total === 1 ? 'spedizione' : 'spedizioni' }}</small></strong></li>@empty<li class="profile-empty">Nessuna spedizione aperta assegnata.</li>@endforelse</ul>
            </section>
            <section class="profile-section profile-access" aria-labelledby="access-title-{{ $rider->slug }}">
                <header><h3 id="access-title-{{ $rider->slug }}">Accesso al gestionale</h3><x-ui.icon name="shield-check" /></header>
                <p @class(['access-state', 'is-pending' => ! $rider->email_verified_at, 'is-disabled' => ! $rider->is_active])><x-ui.icon :name="! $rider->is_active ? 'person-slash' : ($rider->email_verified_at ? 'patch-check' : 'hourglass-split')" />{{ ! $rider->is_active ? 'Account disabilitato' : ($rider->email_verified_at ? 'Email verificata · accesso attivo' : 'Email da verificare al primo accesso') }}</p>
                <div class="access-card-actions">
                    @can('update', $rider)
                        <button type="button" class="btn access-manage-open" data-dialog-open="user-access-{{ $rider->slug }}" aria-controls="user-access-{{ $rider->slug }}" aria-haspopup="dialog"><x-ui.icon name="shield-lock" /> Gestisci accesso</button>
                        <x-ui.modal :id="'user-access-'.$rider->slug" title="Gestisci accesso" :description="$rider->name">
                            <form method="post" action="{{ route('users.update', $rider) }}" class="access-manage-form">@csrf @method('patch')
                                <p class="access-manage-note">Ogni azione disconnette il rider dalle sessioni precedenti.</p>
                                <div class="access-manage-actions">
                                    @if($rider->is_active)
                                        <button type="submit" name="action" value="deactivate" class="btn access-action access-action--disable" data-confirm="Disabilita account" data-confirm-name="{{ $rider->name }}"><x-ui.icon name="person-slash" /><span>Disabilita account<small>Blocca l’accesso senza eliminare dati e storico</small></span></button>
                                    @else
                                        <button type="submit" name="action" value="activate" class="btn access-action access-action--enable"><x-ui.icon name="person-check" /><span>Riattiva account<small>Il rider potrà accedere di nuovo</small></span></button>
                                    @endif
                                    <button type="submit" name="action" value="revoke-devices" class="btn access-action access-action--revoke" data-confirm="Revoca sessione dai dispositivi" data-confirm-name="{{ $rider->name }}"><span class="access-device-icon" aria-hidden="true"><x-ui.icon name="phone" /><x-ui.icon name="box-arrow-right" /></span><span>Revoca sessione dai dispositivi<small>Chiude ogni accesso attivo e i dispositivi autorizzati</small></span></button>
                                    @if($rider->email_verified_at)
                                        <button type="submit" name="action" value="reset-email" class="btn access-action access-action--verify" data-confirm="Ripristina verifica email" data-confirm-name="{{ $rider->name }}"><x-ui.icon name="envelope-exclamation" /><span>Ripristina verifica email<small>Al prossimo accesso servirà di nuovo il codice via email</small></span></button>
                                    @endif
                                </div>
                                <footer class="modal-actions access-manage-footer">
                                    <button type="button" class="btn modal-back access-back modal-back-icon icon-button action-back" data-dialog-close aria-label="Torna indietro" data-tooltip="Torna indietro"><x-ui.icon name="arrow-left" /></button>
                                    @if($rider->is_active && ! $rider->email_verified_at)
                                        <button type="submit" name="action" value="send-activation" class="btn access-send" aria-label="Invia nuovo link di attivazione" data-tooltip="Invia nuovo link di attivazione (crea password, valido 48 ore)" data-confirm-name="{{ $rider->name }}"><x-ui.icon name="send" /></button>
                                    @endif
                                </footer>
                            </form>
                        </x-ui.modal>
                    @endcan
                    @can('delete', $rider)<form method="post" action="{{ route('users.destroy', $rider) }}" class="rider-account-delete" data-confirm="Elimina account" data-confirm-name="{{ $rider->name }}" data-confirm-description="L’account sarà eliminato definitivamente: il rider non potrà più accedere e dispositivi e sessioni saranno revocati. Spedizioni, contabilità e storico restano conservati.">@csrf @method('delete')<x-ui.icon-button type="submit" action="delete" label="Elimina account" text /></form>@endcan
                </div>
            </section>
            <footer class="profile-footer"><button type="button" class="btn btn-outline-secondary directory-return" data-directory-return><x-ui.icon name="arrow-up" /> Elenco Rider</button><a class="btn btn-primary" href="{{ route('rider-operations.show', $rider) }}"><x-ui.icon name="calendar3" /> Dettaglio giornaliero</a></footer>
        </section>
        @endforeach
    </x-ui.directory>
    @else<section class="surface empty-state"><x-ui.icon name="people" /><h2>Il team parte da qui</h2><p>Crea il primo account Rider per assegnare le richieste.</p><a class="btn btn-primary" href="{{ route('users.create') }}">Aggiungi</a></section>@endif
</x-app-layout>
