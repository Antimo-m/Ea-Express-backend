<x-guest-layout title="Verifica cellulare">
    <span class="eyebrow">SOLO AL PRIMO ACCESSO</span><h1 class="auth-title">Verifica il tuo cellulare.</h1><p class="text-secondary">Inserisci il tuo numero per verificare l’account. Dopo questo passaggio, ti basteranno email e password.</p>
    @if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
    <form method="post" action="{{ route('phone.send') }}" class="form-stack mb-4">@csrf
        <x-ui.field name="phone" label="Numero di cellulare" type="tel" :value="$user->pending_phone" autocomplete="tel" placeholder="+39 333 1234567" required help="Per numeri esteri, includi il prefisso internazionale. Puoi correggere il numero e richiedere un nuovo codice." />
        <button class="btn btn-primary">{{ $user->phone_otp_sent_at ? 'Invia un nuovo codice SMS' : 'Invia codice SMS' }}</button>
        <p class="small text-secondary mb-0">Attendi almeno un minuto tra gli invii. Ogni nuovo codice sostituisce il precedente.</p>
    </form>
    @if($user->phone_otp_hash && $user->phone_otp_expires_at?->isFuture())
    <form method="post" action="{{ route('phone.verify') }}" class="form-stack border-top pt-4">@csrf
        <x-ui.field name="code" label="Codice OTP di 4 cifre" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{4}" minlength="4" maxlength="4" required help="Il codice scade dopo 15 minuti dall’invio e può essere utilizzato una sola volta." />
        <button class="btn btn-primary">Verifica e accedi <x-ui.icon name="arrow-right" /></button>
    </form>
    @elseif($user->phone_otp_sent_at)<p class="alert alert-secondary">Il codice non è più disponibile. Richiedi un nuovo SMS.</p>@endif
    <form method="post" action="{{ route('logout') }}" class="mt-4">@csrf<button class="btn btn-outline-secondary w-100">Esci dall’account</button></form>
</x-guest-layout>
