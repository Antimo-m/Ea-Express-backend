<x-guest-layout>
    <h1 class="h4">Verifica il tuo accesso</h1>
    <p>Inserisci il codice inviato a <strong>{{ $user->email }}</strong>. Dopo la verifica entrerai direttamente nella tua dashboard.</p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="text-danger" role="alert">{{ $errors->first() }}</div>@endif
    <form method="post" action="{{ route('email-otp.verify') }}" class="form-stack">
        @csrf
        <x-ui.field name="code" label="Codice email" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" required />
        <button class="btn btn-primary">Completa l’accesso</button>
    </form>
    <form method="post" action="{{ route('email-otp.send') }}" class="mt-3">@csrf<button class="btn btn-outline-primary">Invia un nuovo codice</button></form>
    <p class="small text-secondary mt-2">Il codice scade dopo {{ (int) ceil(config('auth_security.otp.ttl_seconds') / 60) }} minuti. Puoi richiederne uno nuovo dopo {{ config('auth_security.otp.resend_seconds') }} secondi, fino a {{ config('auth_security.otp.resends') }} reinvii in {{ (int) ceil(config('auth_security.otp.window_seconds') / 60) }} minuti.</p>
    <form method="post" action="{{ route('logout') }}">@csrf<button class="btn btn-link">Esci</button></form>
</x-guest-layout>
