<x-guest-layout>
    <h1 class="h4">Verifica la tua email</h1>
    <p>Inserisci il codice inviato a <strong>{{ $user->email }}</strong>. Dopo la verifica potrai accedere con email e password.</p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="text-danger" role="alert">{{ $errors->first() }}</div>@endif
    <form method="post" action="{{ route('email-otp.verify') }}" class="form-stack">
        @csrf
        <x-ui.field name="code" label="Codice email" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" required />
        <button class="btn btn-primary">Verifica email</button>
    </form>
    <form method="post" action="{{ route('email-otp.send') }}" class="mt-3">@csrf<button class="btn btn-outline-primary">Invia un nuovo codice</button></form>
    <p class="small text-secondary mt-2">Il codice scade dopo 15 minuti. Puoi richiederne uno nuovo dopo un minuto, fino a tre invii all’ora.</p>
    <form method="post" action="{{ route('logout') }}">@csrf<button class="btn btn-link">Esci</button></form>
</x-guest-layout>
