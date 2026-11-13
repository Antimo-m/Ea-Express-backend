<x-guest-layout title="Password dimenticata">
    <header class="staff-login-heading"><span class="staff-login-mark" aria-hidden="true"><x-ui.icon name="key" /></span><span class="eyebrow">RIPRISTINO ACCESSO RIDER</span><h1 class="auth-title">Password dimenticata?</h1><p>Inserisci l’email del tuo account: ti invieremo un link valido {{ config('auth.passwords.users.expire') }} minuti per sceglierne una nuova.</p></header>
    <form method="post" action="{{ route('password.email') }}" class="form-stack" data-password-recovery>@csrf
        <x-ui.field name="email" label="Indirizzo email" type="email" :value="old('email')" autocomplete="username" maxlength="255" required autofocus />
        <button class="btn btn-primary auth-submit" type="submit">Invia il link <x-ui.icon name="arrow-right" class="ms-2" /></button>
    </form>
    <p class="small text-secondary mt-3 mb-0">La password dell’account Admin non si recupera da qui: viene gestita soltanto dal server.</p>
    <p class="mt-3 mb-0"><a href="{{ route('login') }}">Torna all’accesso</a></p>
</x-guest-layout>
