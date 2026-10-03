# Sicurezza autenticazione staff — 2 ottobre 2026

## Ambito e policy effettiva

Analisi del Gestionale Laravel 13.30.1 / PHP 8.5 e delle API del portale clienti. Il finding è confermato nella variante **verifica email non completata → cambio password**. Nel codice attuale non esiste una challenge telefonica attiva né MFA obbligatoria a ogni login: i Rider verificano l’email una volta, gli Admin sono esenti da questa verifica, i Customer usano un guard distinto. `phone_verified_at` e i campi OTP telefonici sono residui dati, non una prova MFA della sessione. L’enum dei ruoli contiene Admin, Rider e Customer; “staff” indica Admin/Rider e non un quarto ruolo.

Questa correzione applica tutti i requisiti di accesso attualmente previsti. Non introduce di nascosto SMS, TOTP, OTP ricorrente o MFA Admin. Un’email verificata è uno stato dell’account; la prova di accesso è ora uno stato della specifica sessione. Non bisogna descrivere questa policy come MFA telefonica a ogni accesso. Se quella è la policy desiderata, occorre definire e implementare un fattore aggiuntivo: il flusso attuale non lo fornisce.

## Report richiesto

### 1. Causa della vulnerabilità

`Auth::attempt` impostava immediatamente l’utente sul guard `web` e salvava la sessione Laravel, anche per un Rider con email da verificare. Il redirect alla pagina OTP non riduceva i privilegi di questa sessione. `auth` dimostra l’autenticazione primaria; `auth.session` controlla la coerenza della password, non il completamento della verifica. Mancava una prova centralizzata di completamento dell’accesso.

### 2. Percorso vulnerabile esatto

`POST /login` con credenziali Rider valide → sessione web autenticata → redirect `/verify-email-otp` → senza inviare OTP, richiesta diretta `PUT /password` con password attuale corretta e nuova password confermata → controller/action potevano salvare la password. Conoscere la password primaria era sufficiente. Il nuovo test esercita questo percorso attraverso il vero endpoint login e verifica 403 e hash password invariato.

### 3. Route vulnerabili

`PUT /password`, `GET /confirm-password`, `POST /confirm-password` avevano soltanto `auth`, `auth.session` e throttling, senza requisito di verifica staff. Le pagine operative e il profilo avevano già `EnsureStaff`/`EnsureEmailVerified`; quella protezione non copriva il gruppo auth e non rappresentava la verifica della sessione corrente. Il “Ricordami” poteva inoltre essere emesso durante la prima autenticazione, prima dell’OTP.

### 4. Middleware analizzati

`auth`, `guest`, `auth.session`, `EnsureStaff`, `EnsureAdmin`, `EnsureCustomer`, `EnsureActiveAccount`, `EnsureEmailVerified`, `PreservePageNavigation`/`StartSession`, CSRF e limiter. Sono mantenuti i controlli di ruolo, account attivo, ownership e autorizzazione dei singoli endpoint. Il controllo di completamento non attribuisce permessi amministrativi ai Rider.

### 5. Meccanismo centralizzato

`StaffAuthentication` determina policy, prova, binding e transizioni; `StaffAuthenticationState` distingue `Guest`, `PrimaryAuthenticated`, `FactorVerified`, `FullyAuthenticated`. `EnsureStaffAuthentication` fa parte del gruppo `web` globale, prima di `auth` e del model binding: ogni richiesta di una sessione staff parziale viene negata, comprese AJAX e API clienti. Non serve ricordarsi il middleware in ogni nuova route web. Le API clienti sono anch’esse route web stateful e conservano il proprio guard e controllo ruolo. Le action `ChangePassword` e `UpdateProfile` ricontrollano la prova per utenti staff sotto lock del record.

### 6. Sessione parziale

Dopo le credenziali si rigenera/distrugge l’ID precedente e si registra una prova server-side contenente utente, HMAC dell’ID di sessione, impronta HMAC delle credenziali/ruolo/attivazione, istante iniziale e stato. Se è richiesta la verifica, sono consentite esclusivamente le route nominate `email-otp.notice`, `email-otp.send`, `email-otp.verify`, `logout`. Le altre rispondono 403 JSON oppure redirect alla challenge. La prova parziale scade dopo 30 minuti; una prova assente/non valida richiede un nuovo login. I flag client non vengono letti per elevare lo stato.

### 7. Sessione completa

OTP valido e consumo atomico → stato transitorio `FactorVerified` → controller completa l’accesso → nuova sessione `FullyAuthenticated`. Questo stato transitorio non autorizza endpoint privilegiati. Per Admin e Rider già verificati l’accesso può completarsi dopo la password perché non è configurato un ulteriore fattore; ciò è esplicito nella policy. La verifica dell’account effettuata da un altro dispositivo non eleva una prova ancora `PrimaryAuthenticated`: viene richiesto un nuovo login, senza loop tra dashboard e challenge.

### 8. Rotazione sessione e CSRF

`regenerate(true)` dopo autenticazione primaria, verifica OTP, cambio password e cambio email staff: nuovo ID, distruzione del precedente e rigenerazione CSRF. La prova viene legata al nuovo ID. Logout invalida l’intera sessione e rigenera CSRF. I test mantengono un cookie di sessione reale tra richieste, anziché ignorare il binding.

### 9. Binding challenge

Challenge server-side: user ID, UUID challenge, tipo `email_verification`, HMAC dell’ID sessione, issued/expires timestamp e fingerprint SHA-256 del verificatore OTP nel DB. Il codice è verificato tramite hash del digest HMAC di utente/email/codice. Tutti i confronti dei binding usano `hash_equals`. Un’altra sessione dello stesso utente o un altro utente non possono utilizzare il codice; copiare la challenge precedente dopo un reinvio non funziona.

### 10. TTL

OTP e challenge: 15 minuti, con scadenza controllata sia lato DB sia nella challenge. Dopo scadenza il verificatore DB viene cancellato; la sessione rimane senza privilegi. Sessione parziale: massimo 30 minuti. Reset password: scadenza già configurata a 60 minuti.

### 11. Replay e concorrenza

Verifica/consumo in `DB::transaction` con `lockForUpdate`, controlli sul record corrente e cancellazione del verificatore al successo. Reinvio sostituisce il verificatore; logout invalida soltanto la challenge effettivamente appartenente a quella sessione, senza cancellare una challenge più recente di un altro dispositivo. Invio, conferma e logout usano anche il lock di sessione Laravel (10 secondi di lock/attesa). `PreservePageNavigation` ora riceve correttamente il resolver cache richiesto dal blocco. Il test con due processi PHP simultanei, stessa challenge e sessione, ottiene un solo successo e un solo audit; l’altro viene negato dopo la distruzione della sessione originaria. Il test usa SQLite e session/cache file isolate, non il DB operativo. Il lock DB MySQL non è stato collaudato con traffico parallelo in produzione.

### 12. Rate limiting

Login staff: 5 errori per email/IP, 20 richieste/minuto per IP e 10/minuto per account anche cambiando IP. Login clienti: 20/minuto IP, 5/minuto email/IP e 10/minuto account. OTP: cooldown 60 secondi, 3 invii/ora per utente nel DB, invio HTTP 10/minuto utente e 15/ora IP; verifica HTTP 5/minuto utente, 20/ora utente e 20/minuto IP, oltre a 5 codici errati per challenge. Recovery: 3/ora IP e 3/ora email; reset: 10/minuto IP e 5/minuto email. Restano i limiter generali sulle scritture. Non sono presenti recovery codes ai quali applicare altri limiter.

### 13. Endpoint alternativi

Controllati password, conferma password, profilo/email, eliminazione account, gestione utenti/ruoli, impostazioni/accounting, pagamenti, incassi, movimenti, spese, ordini, segnalazioni destinatari, report/audit e realtime. Le richieste dirette sono protette dal guard globale prima del controller, della validazione e del model binding. Vecchie route `/verify-email`, link di verifica email, `/email/verification-notification` e registrazione staff pubblica sono assenti (404). Non sono presenti endpoint per modifica/disattivazione MFA, backup codes, API key o telefono di autenticazione.

### 14. Recovery

`RecoverAccount`, controller staff e `CustomerAuthController` usano il broker Laravel con ruoli consentiti e account attivo. Il reset non esegue autologin e non imposta la prova di accesso. Al successivo login un Rider non verificato torna alla challenge. Reset monouso, URL di recupero basati sulla configurazione anziché Host del client, assenza di enumerazione degli account, invalidazione di token dopo cambio email/password/eliminazione e isolamento staff/clienti sono coperti dai test esistenti rieseguiti. Una sessione parziale deve fare logout per utilizzare il flusso pubblico di recupero.

### 15. Token e API

Guard `web` e `customer` entrambi session-based. Nessun Sanctum, JWT, personal access token, API key o endpoint di emissione token privilegiati è configurato. Il cookie pre-verifica mantiene soltanto i privilegi della allowlist. Il guard cliente richiede ruolo Customer e non è un’alternativa per autenticare Admin/Rider. Le richieste API da una sessione staff parziale vengono negate dal controllo globale; il tentativo di login cliente con credenziali staff è quindi negato prima di emettere una sessione cliente.

### 16. Remember-device

Non esiste “dispositivo fidato MFA”. Esiste il remember-me Laravel: login primario usa `remember=false`; l’intenzione viene salvata nella prova e il cookie persistente viene emesso soltanto dopo il completamento dei requisiti. Per account che non richiedono verifica resta disponibile dopo la password. Al recupero da cookie Laravel si crea una nuova prova conforme alla policy corrente; un Rider non verificato rimane parziale. Cambio password/email/ruolo/attivazione e logout revocano/ruotano il remember token. Questo meccanismo non deve essere reinterpretato come esenzione da un futuro MFA per sessione.

### 17. Backup codes

Non implementati: nessuna tabella, route o action di recovery codes/backup codes MFA. Non sono stati introdotti. I backup del database non sono recovery codes di autenticazione.

### 18. Logging

Eventi `security.primary_authenticated`, `authentication_completed`, `otp_challenge_created`, `otp_sent`, `otp_verified`, `otp_failed`, `otp_expired`, `otp_challenge_mismatch`, `otp_replay_or_invalid_challenge`, `partial_auth_access_denied`, `password_changed`, `password_reset`, `logout`; rimangono `login_failed`, `rate_limited`, `email_changed`. Contesto limitato a user ID, IP, route e request ID. Nessun OTP, password, identificatore di sessione in chiaro, hash password o token viene aggiunto al contesto. Un test verifica i campi ammessi. Non si registrano eventi per disattivazione MFA/recovery codes, perché quei flussi non esistono.

### 19. Test aggiunti

`StaffAuthenticationSecurityTest`: 20 casi per il percorso reale di login, password negata con flag client falsificati, endpoint alternativi, API, futura route priva di middleware specifico, successo e autorizzazioni di ruolo, rotazione ID/CSRF, codice errato/scaduto, replay, crossing utente/sessione, reinvio, logout, verifica da altro dispositivo, reset, remember-me, Admin/Rider, proof assente/scaduta, difesa nelle action, revoca sessioni/token e logging. `StaffOtpConcurrencyTest`: due processi simultanei e un solo consumo/audit. Sono mantenuti e rieseguiti i test OTP preesistenti e le suite di sicurezza/auth/recovery/portale.

### 20. Varianti trovate e corrette

Accesso password e conferma password pre-verifica; remember-me prematuro; assenza di binding esplicito all’ID sessione; elevazione implicita di una sessione pendente tramite flag account verificato altrove; action sensibili prive di verifica completa; futura route web senza protezione specifica; middleware custom incompatibile con lock sessione. Risolto anche il possibile loop di redirect quando un’altra sessione verifica l’account. Non sono state rilevate emissioni JWT/Sanctum o route legacy MFA attive.

### 21. File modificati per questo intervento

Nuovi: `app/StaffAuthenticationState.php`, `app/Support/StaffAuthentication.php`, `app/Http/Middleware/EnsureStaffAuthentication.php`, `tests/Feature/StaffAuthenticationSecurityTest.php`, `tests/Feature/StaffOtpConcurrencyTest.php`, questo report.

Modificati: `bootstrap/app.php`, `routes/auth.php`, `app/Http/Requests/Auth/LoginRequest.php`, `app/Http/Controllers/Auth/AuthenticatedSessionController.php`, `EmailOtpController.php`, `PasswordController.php`, `app/Http/Controllers/ProfileController.php`, `app/Actions/SendEmailOtp.php`, `VerifyEmailOtp.php`, `ChangePassword.php`, `UpdateProfile.php`, `RecoverAccount.php`, `app/Http/Middleware/PreservePageNavigation.php`, `tests/TestCase.php`, `tests/Feature/EmailChangeSecurityTest.php`, `PaymentConcurrencyTest.php`, `OtpConcurrencyTest.php`, `.gitignore`. `RiderEmailOtpTest.php` è stato incluso nella suite versionabile e formattato. Le molte altre modifiche presenti nel workspace appartengono agli interventi precedenti.

Il test concorrenza pagamenti ora esegue un vero login invece di assegnare direttamente il guard senza prova di accesso. Il test di cambio email sotto lock autentica il proprietario prima di verificare che la vecchia password venga respinta. Il test diretto di concorrenza OTP è stato adattato a una prova primaria legata allo stesso ID di sessione, mantenendo la verifica del consumo atomico nel DB; si affianca al nuovo test HTTP simultaneo. Le action applicative non sono più invocabili come operazioni staff anonime.

### 22. Database e rilascio

Nessuna nuova migrazione per questo fix. Riutilizzati i campi OTP e le sessioni già presenti. Cache e sessioni locali risultano configurate su database; il lock deve usare un backend condiviso nei deployment multiprocesso/multinodo. La prova nuova è obbligatoria: sessioni staff preesistenti senza prova devono rifare login; un cookie remember valido può ripristinare l’accesso secondo la policy attuale. Nessun deploy o commit eseguito. Nessuna modifica a dipendenze o dati operativi.

### 23. Risultati e limiti della verifica

- Suite mirata finale: **141 test superati, 867 assertion**, 21,45 secondi. Comprende tutte le nuove prove, OTP, concorrenza HTTP/DB, concorrenza pagamenti, Auth, recovery, cambio email, profilo, portale clienti e request security.
- Suite completa: **489 superati, 3 falliti**, 4.498 assertion, 110,51 secondi. I tre fallimenti sono aspettative UI rimaste al comportamento precedente: `RecipientRiskTest.php:47` richiede “1 precedenti”, `RecipientRiskTest.php:99` richiede “2 precedenti attivi”, `TerritorialRevisionTest.php:153` richiede “Pagina 1 di 2”. Le prime due diciture erano state eliminate nelle modifiche destinatari richieste; la paginazione è stata ridisegnata nell’intervento UI precedente. Le risposte alle pagine risultano 200 e i controlli dati precedenti passano. Queste assertion UI non sono state modificate in questo intervento di sicurezza e la suite completa non è dichiarata verde.
- `vendor/bin/pint --dirty --format agent`: superato.
- `git diff --check`: superato.
- Inventario Artisan: 135 route applicative.

Comando della suite mirata:

```sh
php artisan test --compact tests/Feature/StaffAuthenticationSecurityTest.php tests/Feature/StaffOtpConcurrencyTest.php tests/Feature/OtpConcurrencyTest.php tests/Feature/PaymentConcurrencyTest.php tests/Feature/RiderEmailOtpTest.php tests/Feature/SecurityBoundaryTest.php tests/Feature/Auth tests/Feature/AccountRecoverySecurityTest.php tests/Feature/EmailChangeSecurityTest.php tests/Feature/ProfileTest.php tests/Feature/CustomerPortalTest.php tests/Feature/RequestSecurityTest.php
```

Comando della suite completa: `php artisan test --compact`.

La verifica riguarda il codice attuale e test isolati. Non è una certificazione dell’assenza di ogni vulnerabilità; non è stato effettuato un penetration test sul deployment esterno né introdotto un nuovo secondo fattore. Per la rotazione dell’ID al cambio di privilegio e la distinzione fra MFA configurato e stato della sessione sono stati consultati [OWASP Session Management](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html) e [OWASP Multifactor Authentication](https://cheatsheetseries.owasp.org/cheatsheets/Multifactor_Authentication_Cheat_Sheet.html).

## Inventario completo delle route applicative

Generato con `php artisan route:list --except-vendor --json`. 135 route: 83 staff, di cui 4 disponibili alla sessione primaria (challenge e logout) e 79 che richiedono accesso completo; 35 Customer con guard dedicato; 17 pubbliche/recovery. La tabella mostra i middleware di route; `web` include sempre il nuovo controllo globale. Anche le route pubbliche e Customer vengono bloccate se la stessa sessione contiene uno staff ancora parziale. L’inventario non include route vendor/health interne.

| Metodo | URI | Requisito | Middleware di route |
| --- | --- | --- | --- |
| GET / HEAD | `/` | Pubblica/recovery; nessun privilegio staff | web, throttle:public-pages |
| POST | `/api/v1/customer/auth/forgot-password` | Pubblica/recovery; nessun privilegio staff | web, throttle:customer-api, throttle:recovery |
| POST | `/api/v1/customer/auth/login` | Pubblica/recovery; nessun privilegio staff | web, throttle:customer-api, throttle:customer-login, throttle:login-account |
| POST | `/api/v1/customer/auth/logout` | Auth Customer + ruolo Customer | web, throttle:customer-api, auth:customer, auth.session, EnsureCustomer |
| GET / HEAD | `/api/v1/customer/auth/me` | Auth Customer + ruolo Customer | web, throttle:customer-api, auth:customer, auth.session, EnsureCustomer |
| POST | `/api/v1/customer/auth/register` | Pubblica/recovery; nessun privilegio staff | web, throttle:customer-api, throttle:registration |
| POST | `/api/v1/customer/auth/reset-password` | Pubblica/recovery; nessun privilegio staff | web, throttle:customer-api, throttle:reset-password |
| GET / HEAD | `/api/v1/customer/booking-rules` | Auth Customer + ruolo Customer | web, throttle:customer-api, auth:customer, auth.session, EnsureCustomer |
| GET / HEAD | `/api/v1/customer/couriers` | Auth Customer + ruolo Customer | web, throttle:customer-api, auth:customer, auth.session, EnsureCustomer |
| GET / HEAD | `/api/v1/customer/csrf` | Pubblica/recovery; nessun privilegio staff | web, throttle:customer-api |
| GET / HEAD | `/api/v1/customer/dashboard` | Auth Customer + ruolo Customer | web, throttle:customer-api, auth:customer, auth.session, EnsureCustomer |
| GET / HEAD | `/api/v1/customer/notifications` | Auth Customer + ruolo Customer | web, throttle:customer-api, auth:customer, auth.session, EnsureCustomer |
| GET / HEAD | `/api/v1/customer/notifications/feed` | Auth Customer + ruolo Customer | web, throttle:customer-api, auth:customer, auth.session, EnsureCustomer |
| GET / HEAD | `/api/v1/customer/notifications/orders/{orderId}` | Auth Customer + ruolo Customer | web, throttle:customer-api, auth:customer, auth.session, EnsureCustomer |
| PATCH | `/api/v1/customer/notifications/read-all` | Auth Customer + ruolo Customer | web, throttle:customer-api, auth:customer, auth.session, EnsureCustomer, throttle:writes |
| PATCH | `/api/v1/customer/notifications/{id}/read` | Auth Customer + ruolo Customer | web, throttle:customer-api, auth:customer, auth.session, EnsureCustomer, throttle:writes |
| GET / HEAD | `/api/v1/customer/orders` | Auth Customer + ruolo Customer | web, throttle:customer-api, auth:customer, auth.session, EnsureCustomer |
| POST | `/api/v1/customer/orders` | Auth Customer + ruolo Customer | web, throttle:customer-api, auth:customer, auth.session, EnsureCustomer, throttle:writes |
| POST | `/api/v1/customer/orders/checkout` | Auth Customer + ruolo Customer | web, throttle:customer-api, auth:customer, auth.session, EnsureCustomer, throttle:writes |
| GET / HEAD | `/api/v1/customer/orders/{order}` | Auth Customer + ruolo Customer | web, throttle:customer-api, auth:customer, auth.session, EnsureCustomer |
| PATCH | `/api/v1/customer/orders/{order}` | Auth Customer + ruolo Customer | web, throttle:customer-api, auth:customer, auth.session, EnsureCustomer, throttle:writes |
| POST | `/api/v1/customer/orders/{order}/cancel` | Auth Customer + ruolo Customer | web, throttle:customer-api, auth:customer, auth.session, EnsureCustomer, throttle:writes |
| POST | `/api/v1/customer/orders/{order}/checkout` | Auth Customer + ruolo Customer | web, throttle:customer-api, auth:customer, auth.session, EnsureCustomer, throttle:writes |
| GET / HEAD | `/api/v1/customer/orders/{order}/location` | Auth Customer + ruolo Customer | web, throttle:customer-api, auth:customer, auth.session, EnsureCustomer, throttle:120,1 |
| GET / HEAD | `/api/v1/customer/orders/{order}/messages` | Auth Customer + ruolo Customer | web, throttle:customer-api, auth:customer, auth.session, EnsureCustomer |
| POST | `/api/v1/customer/orders/{order}/messages` | Auth Customer + ruolo Customer | web, throttle:customer-api, auth:customer, auth.session, EnsureCustomer, throttle:writes |
| PATCH | `/api/v1/customer/orders/{order}/messages/read` | Auth Customer + ruolo Customer | web, throttle:customer-api, auth:customer, auth.session, EnsureCustomer, throttle:realtime-writes |
| PATCH | `/api/v1/customer/orders/{order}/price` | Auth Customer + ruolo Customer | web, throttle:customer-api, auth:customer, auth.session, EnsureCustomer, throttle:writes |
| PUT | `/api/v1/customer/password` | Auth Customer + ruolo Customer | web, throttle:customer-api, auth:customer, auth.session, EnsureCustomer, throttle:writes |
| GET / HEAD | `/api/v1/customer/pending` | Auth Customer + ruolo Customer | web, throttle:customer-api, auth:customer, auth.session, EnsureCustomer |
| GET / HEAD | `/api/v1/customer/pending/{account}` | Auth Customer + ruolo Customer | web, throttle:customer-api, auth:customer, auth.session, EnsureCustomer |
| PATCH | `/api/v1/customer/preferences` | Auth Customer + ruolo Customer | web, throttle:customer-api, auth:customer, auth.session, EnsureCustomer, throttle:writes |
| PATCH | `/api/v1/customer/profile` | Auth Customer + ruolo Customer | web, throttle:customer-api, auth:customer, auth.session, EnsureCustomer, throttle:writes, throttle:profile-update |
| GET / HEAD | `/api/v1/customer/rates` | Auth Customer + ruolo Customer | web, throttle:customer-api, auth:customer, auth.session, EnsureCustomer |
| GET / HEAD | `/api/v1/customer/rates/quote` | Auth Customer + ruolo Customer | web, throttle:customer-api, auth:customer, auth.session, EnsureCustomer |
| POST | `/api/v1/customer/realtime/auth` | Auth Customer + ruolo Customer | web, throttle:customer-api, auth:customer, auth.session, EnsureCustomer, throttle:realtime-writes |
| GET / HEAD | `/api/v1/customer/realtime/configuration` | Auth Customer + ruolo Customer | web, throttle:customer-api, auth:customer, auth.session, EnsureCustomer, throttle:60,1 |
| GET / HEAD | `/api/v1/customer/sender-address` | Auth Customer + ruolo Customer | web, throttle:customer-api, auth:customer, auth.session, EnsureCustomer |
| PUT | `/api/v1/customer/sender-address` | Auth Customer + ruolo Customer | web, throttle:customer-api, auth:customer, auth.session, EnsureCustomer, throttle:writes |
| DELETE | `/api/v1/customer/sender-address` | Auth Customer + ruolo Customer | web, throttle:customer-api, auth:customer, auth.session, EnsureCustomer, throttle:writes |
| GET / HEAD | `/api/v1/customer/statistics` | Auth Customer + ruolo Customer | web, throttle:customer-api, auth:customer, auth.session, EnsureCustomer |
| GET / HEAD | `/balance` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, EnsureAdmin |
| POST | `/balance/{order}/payment` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:writes, EnsureAdmin |
| GET / HEAD | `/booking-rules` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified |
| GET / HEAD | `/confirm-password` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, throttle:writes, EnsureStaff |
| POST | `/confirm-password` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, throttle:writes, EnsureStaff |
| GET / HEAD | `/conversation/{token}` | Pubblica/recovery; nessun privilegio staff | web, throttle:conversation |
| POST | `/conversation/{token}` | Pubblica/recovery; nessun privilegio staff | web, throttle:conversation-write |
| GET / HEAD | `/dashboard` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified |
| GET / HEAD | `/economic-audits` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, EnsureAdmin |
| POST | `/expenses` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:writes, EnsureAdmin |
| PATCH | `/expenses/{expense}` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:writes, EnsureAdmin |
| DELETE | `/expenses/{expense}` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:writes, EnsureAdmin |
| GET / HEAD | `/forgot-password` | Pubblica/recovery; nessun privilegio staff | web, guest, throttle:public-pages |
| POST | `/forgot-password` | Pubblica/recovery; nessun privilegio staff | web, guest, throttle:public-pages, throttle:recovery |
| GET / HEAD | `/labels` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified |
| GET / HEAD | `/login` | Pubblica/recovery; nessun privilegio staff | web, guest, throttle:public-pages |
| POST | `/login` | Pubblica/recovery; nessun privilegio staff | web, guest, throttle:public-pages, throttle:login-ip, throttle:login-account |
| POST | `/logout` | Auth staff primaria: challenge/logout | web, auth, auth.session, throttle:writes |
| GET / HEAD | `/messages` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified |
| GET / HEAD | `/messages/{order}` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified |
| POST | `/messages/{order}` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:writes |
| PATCH | `/messages/{order}/read` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:realtime-writes |
| POST | `/messages/{order}/share` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:writes |
| GET / HEAD | `/movements` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, EnsureAdmin |
| POST | `/movements` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:writes, EnsureAdmin |
| GET / HEAD | `/movements/{movement}` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, EnsureAdmin |
| PATCH | `/movements/{movement}` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:writes, EnsureAdmin |
| DELETE | `/movements/{movement}` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:writes, EnsureAdmin |
| GET / HEAD | `/notifications` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified |
| GET / HEAD | `/notifications/feed` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:60,1 |
| GET / HEAD | `/notifications/orders/{orderId}` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:60,1 |
| PATCH | `/notifications/read-all` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:writes |
| PATCH | `/notifications/{notification}` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:writes |
| GET / HEAD | `/orders` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified |
| POST | `/orders` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:writes, EnsureAdmin |
| POST | `/orders/checkout/edit` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:writes, EnsureAdmin |
| GET / HEAD | `/orders/create` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, EnsureAdmin |
| GET / HEAD | `/orders/history` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified |
| GET / HEAD | `/orders/in-progress` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified |
| GET / HEAD | `/orders/incoming` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified |
| GET / HEAD | `/orders/{order}` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified |
| PATCH | `/orders/{order}` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:writes |
| PATCH | `/orders/{order}/carrier` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:writes |
| GET / HEAD | `/orders/{order}/location` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:120,1 |
| PUT | `/orders/{order}/location` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:20,1 |
| POST | `/orders/{order}/location/session` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:20,1 |
| DELETE | `/orders/{order}/location/session` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:20,1 |
| PATCH | `/orders/{order}/map-points` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:writes |
| PATCH | `/orders/{order}/pickup-schedule` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:writes |
| PATCH | `/orders/{order}/price` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:writes, EnsureAdmin |
| PATCH | `/orders/{order}/rider` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, EnsureAdmin, throttle:writes |
| PUT | `/password` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, throttle:writes, EnsureStaff |
| GET / HEAD | `/pending` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, EnsureAdmin |
| POST | `/pending` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:writes, EnsureAdmin |
| PATCH | `/pending/{account}` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:writes, EnsureAdmin |
| POST | `/pending/{account}/settlements` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:writes, EnsureAdmin |
| PATCH | `/pending/{account}/settlements/{settlement}` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:writes, EnsureAdmin |
| GET / HEAD | `/pickups` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified |
| GET / HEAD | `/profile` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:writes |
| PATCH | `/profile` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:writes, throttle:profile-update |
| DELETE | `/profile` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:writes |
| GET / HEAD | `/rates` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, EnsureAdmin |
| GET / HEAD | `/rates/quote` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, EnsureAdmin |
| POST | `/rates/{rate?}` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:writes, EnsureAdmin |
| DELETE | `/rates/{rate}` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:writes, EnsureAdmin |
| GET / HEAD | `/rates/{rate}/history` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, EnsureAdmin |
| PATCH | `/rates/{rate}/state` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:writes, EnsureAdmin |
| POST | `/realtime/auth` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:realtime-writes |
| GET / HEAD | `/realtime/configuration` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:60,1 |
| GET / HEAD | `/recipient-incidents` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, EnsureAdmin |
| PATCH | `/recipient-incidents/{incident}` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, EnsureAdmin, throttle:writes |
| GET / HEAD | `/recipient-incidents/{profile}` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, EnsureAdmin |
| GET / HEAD | `/reports` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, EnsureAdmin |
| POST | `/reset-password` | Pubblica/recovery; nessun privilegio staff | web, guest, throttle:public-pages, throttle:reset-password |
| GET / HEAD | `/reset-password/{token}` | Pubblica/recovery; nessun privilegio staff | web, guest, throttle:public-pages |
| GET / HEAD | `/rider-operations` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, EnsureAdmin |
| GET / HEAD | `/rider-operations/feed` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, EnsureAdmin, throttle:60,1 |
| GET / HEAD | `/rider-operations/{rider}` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, EnsureAdmin |
| PATCH | `/settings` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:writes, EnsureAdmin |
| GET / HEAD | `/settings` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, EnsureAdmin |
| PATCH | `/settings/accounting` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:writes, EnsureAdmin |
| GET / HEAD | `/settings/users` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, EnsureAdmin |
| POST | `/settings/users` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:writes, EnsureAdmin |
| GET / HEAD | `/settings/users/create` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, EnsureAdmin |
| PATCH | `/settings/users/{user}` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:writes, EnsureAdmin |
| GET / HEAD | `/stores` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, EnsureAdmin |
| GET / HEAD | `/track/{token}` | Pubblica/recovery; nessun privilegio staff | web, throttle:tracking |
| POST | `/track/{token}/realtime/auth` | Pubblica/recovery; nessun privilegio staff | web, throttle:tracking |
| GET / HEAD | `/track/{token}/realtime/configuration` | Pubblica/recovery; nessun privilegio staff | web, throttle:tracking |
| GET / HEAD | `/tracking` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified |
| GET / HEAD | `/tracking/positions` | Auth staff + FullyAuthenticated + permessi | web, auth, auth.session, EnsureStaff, EnsureEmailVerified, throttle:60,1 |
| GET / HEAD | `/verify-email-otp` | Auth staff primaria: challenge/logout | web, auth, auth.session, throttle:writes, EnsureStaff |
| POST | `/verify-email-otp/confirm` | Auth staff primaria: challenge/logout | web, auth, auth.session, throttle:writes, EnsureStaff, throttle:otp-check |
| POST | `/verify-email-otp/send` | Auth staff primaria: challenge/logout | web, auth, auth.session, throttle:writes, EnsureStaff, throttle:otp-send |
