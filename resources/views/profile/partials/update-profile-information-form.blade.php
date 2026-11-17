<section aria-labelledby="profile-title">
    <h2 id="profile-title" class="h5"><x-ui.icon name="person" class="me-2" />Dati personali</h2><p class="text-secondary">Il tuo nome e l’indirizzo email con cui accedi.</p>
    <form method="post" action="{{ route('profile.update') }}" class="form-stack">@csrf @method('patch')
        <x-ui.field name="name" label="Nome e cognome" :value="old('name', $user->name)" autocomplete="name" maxlength="255" required />
        <div class="field">
            <label class="form-label" for="account_email">Indirizzo email</label>
            <input class="form-control" id="account_email" type="email" value="{{ $user->email }}" readonly disabled aria-describedby="account_email-help">
            <div id="account_email-help" class="form-text">L’indirizzo email associato all’account non può essere modificato.</div>
        </div>
        <div><x-ui.icon-button action="confirm" label="Salva dati" type="submit" /></div>
    </form>
</section>
