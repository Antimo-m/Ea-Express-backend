<section aria-labelledby="profile-title">
    <h2 id="profile-title" class="h5"><x-ui.icon name="person" class="me-2" />Dati personali</h2><p class="text-secondary">Il tuo nome e l'indirizzo email per accedere.</p>
    <form method="post" action="{{ route('profile.update') }}" class="form-stack">@csrf @method('patch')
        <x-ui.field name="name" label="Nome e cognome" :value="old('name', $user->name)" autocomplete="name" maxlength="255" required />
        <x-ui.field name="email" label="Indirizzo email" type="email" :value="old('email', $user->email)" autocomplete="username" maxlength="255" required />
        <div><button class="btn btn-primary" type="submit">Salva dati</button></div>
    </form>
    <div class="mt-4"><h3 class="h6">Cellulare</h3><p class="text-secondary">{{ $user->phone_verified_at ? $user->phone.' · Verificato' : 'Verifica cellulare non completata' }}</p><p class="small text-secondary">Per cambiare un numero già verificato, chiedi all’amministratore di ripristinare la verifica.</p></div>
</section>
