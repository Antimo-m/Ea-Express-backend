<section aria-labelledby="profile-title">
    <h2 id="profile-title" class="h5"><x-ui.icon name="person" class="me-2" />Dati personali</h2><p class="text-secondary">Il tuo nome e l'indirizzo email per accedere.</p>
    <form method="post" action="{{ route('profile.update') }}" class="form-stack">@csrf @method('patch')
        <x-ui.field name="name" label="Nome e cognome" :value="old('name', $user->name)" autocomplete="name" maxlength="255" required />
        <x-ui.field name="email" label="Indirizzo email" type="email" :value="old('email', $user->email)" autocomplete="username" maxlength="255" required />
        <div><button class="btn btn-primary" type="submit">Salva dati</button></div>
    </form>
    @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
        <form action="{{ route('verification.send') }}" method="post" class="mt-3">@csrf<p class="text-secondary">Il tuo indirizzo email non è ancora verificato.</p><button class="btn btn-outline-secondary" type="submit">Invia email di verifica</button></form>
    @endif
</section>
