<x-app-layout title="Il mio profilo">
    <x-slot name="header"><span class="eyebrow">IL TUO ACCOUNT</span><h1>Il mio profilo</h1><p class="text-secondary mb-0">Gestisci i tuoi dati e mantieni al sicuro l'accesso.</p></x-slot>
    <div class="profile-grid" data-workspace-region="profile">
        <section class="surface p-4">@include('profile.partials.update-profile-information-form')</section>
        <section class="surface p-4">@include('profile.partials.update-password-form')</section>
        @if(auth()->user()->role === \App\UserRole::Admin)
        <section class="surface p-4 account-closure" aria-labelledby="account-closure-title" data-account-closure>
            <h2 id="account-closure-title" class="h5"><x-ui.icon name="person-x" class="me-2" />Elimina account</h2>
            <p class="text-secondary">L’eliminazione è definitiva: accessi, sessioni e dati di contatto vengono rimossi, mentre spedizioni, contabilità e storico restano conservati.</p>
            <p class="text-secondary">Dopo l’eliminazione non resterà alcun amministratore: il nuovo Admin potrà essere nominato soltanto dal server, con la procedura tecnica riservata.</p>
            <x-ui.action-dialog id="account-closure" action="delete" title="Elimina il tuo account" description="Operazione irreversibile" :open="$errors->accountClosure->isNotEmpty()" text>
                <form method="post" action="{{ route('profile.destroy') }}" class="form-stack mt-3">@csrf @method('delete')
                    @error('account', 'accountClosure')<p class="alert alert-danger" role="alert">{{ $message }}</p>@enderror
                    <x-ui.field name="password" id="account-closure-password" bag="accountClosure" label="Password attuale" type="password" autocomplete="current-password" maxlength="72" required />
                    <x-ui.field name="confirmation" id="account-closure-confirmation" bag="accountClosure" label="Scrivi ELIMINA per confermare" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="10" required />
                    <div class="row-actions"><x-ui.icon-button action="delete" label="Elimina definitivamente" type="submit" text /></div>
                </form>
            </x-ui.action-dialog>
        </section>
        @else
        <section class="surface p-4"><h2 class="h5">Gestione dell’account</h2><p class="mb-0">Per chiudere l’account o per una richiesta sui tuoi dati personali, rivolgiti all’amministratore. Lo storico operativo e contabile viene conservato.</p></section>
        @endif
    </div>
</x-app-layout>
