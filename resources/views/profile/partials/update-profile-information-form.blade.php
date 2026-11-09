<section aria-labelledby="profile-title">
    <h2 id="profile-title" class="h5"><x-ui.icon name="person" class="me-2" />Dati personali</h2><p class="text-secondary">Il tuo nome e l'indirizzo email per accedere.</p>
    <form method="post" action="{{ route('profile.update') }}" class="form-stack">@csrf @method('patch')
        <x-ui.field name="name" label="Nome e cognome" :value="old('name', $user->name)" autocomplete="name" maxlength="255" required />
        <x-ui.field name="email" label="Indirizzo email" type="email" :value="old('email', $user->email)" autocomplete="username" maxlength="255" required />
        <x-ui.field name="current_password" id="profile_current_password" label="Password attuale (per cambiare email)" type="password" autocomplete="current-password" maxlength="72" help="Richiesta solo se modifichi l’indirizzo email." data-required-when="email" :data-required-unless-value="$user->email" :required="old('email', $user->email) !== $user->email" />
        <div><x-ui.icon-button action="confirm" label="Salva dati" type="submit" /></div>
    </form>
</section>
