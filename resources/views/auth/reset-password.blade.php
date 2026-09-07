<x-guest-layout title="Reimposta password">
    <span class="eyebrow">UN NUOVO ACCESSO</span><h1 class="auth-title">Scegli la tua<br>nuova password.</h1>
    <form method="post" action="{{ route('password.store') }}" class="form-stack mt-4">@csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <x-ui.field name="email" label="Indirizzo email" type="email" :value="old('email', $request->email)" autocomplete="username" required />
        <x-ui.field name="password" label="Nuova password" type="password" autocomplete="new-password" minlength="8" help="Almeno 8 caratteri." required autofocus />
        <x-ui.field name="password_confirmation" label="Conferma nuova password" type="password" autocomplete="new-password" required />
        <button class="btn btn-primary w-100" type="submit">Salva nuova password</button>
    </form>
    <a class="back-link mt-4" href="{{ route('login') }}">Torna al login</a>
</x-guest-layout>
