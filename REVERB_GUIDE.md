# Reverb ed Echo in EA-Express

Configurazione verificata con Laravel 13.30.1, Reverb 1.11.1, Echo 2.5 e React 19. I pacchetti e il collegamento sono già presenti nelle due repository: non rieseguire gli installer su questa configurazione.

## 1. Installazione e ambiente backend

Su una nuova applicazione Laravel, l'installazione manuale è:

```bash
composer require laravel/reverb
php artisan reverb:install --no-interaction
```

In alternativa, `php artisan install:broadcasting --reverb` guida la configurazione completa. Nella nostra repository basta `composer install`, che usa le versioni del lockfile senza aggiornare le dipendenze.

Nel `.env` del backend configura:

```dotenv
BROADCAST_CONNECTION=reverb
QUEUE_CONNECTION=database
REVERB_APP_ID=ea-express-local
REVERB_APP_KEY=<chiave-pubblica-generata>
REVERB_APP_SECRET=<segreto-generato>
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080
REVERB_PUBLIC_HOST=localhost
REVERB_PUBLIC_PORT=8080
REVERB_PUBLIC_SCHEME=http
REVERB_ALLOWED_ORIGINS=localhost,127.0.0.1
CUSTOMER_FRONTEND_URL=http://localhost:5173
CUSTOMER_ALLOWED_ORIGINS=http://localhost:5173
```

I segnaposto vanno sostituiti solo nel `.env` locale. Gli installer generano le credenziali; per generarne una manualmente puoi usare `openssl rand -hex 32`. Conserva quelle già configurate nel progetto. `.env`, database, log, chiavi e file `*.code-workspace` sono esclusi da Git; `.env.example` contiene solo valori di esempio e campi vuoti.

`REVERB_SERVER_*` definisce dove il processo ascolta; `REVERB_HOST/PORT/SCHEME` indica dove Laravel pubblica gli eventi; `REVERB_PUBLIC_*` è l'indirizzo raggiungibile dal browser. In locale possono coincidere. In produzione usa il dominio WebSocket pubblico con HTTPS/WSS, origini esplicite e un reverse proxy verso il processo Reverb interno. Il segreto resta sempre sul backend; la app key è pubblica per definizione. Non creare variabili `VITE_*` contenenti segreti.

## 2. Autenticazione dei canali privati

`routes/channels.php` contiene le regole reali del progetto:

```php
use App\Models\User;
use App\UserRole;
use Illuminate\Support\Facades\Broadcast;

Broadcast::connection('reverb')->channel('customer.{id}', function (User $user, string $id): bool {
    return $user->is_active
        && $user->role === UserRole::Customer
        && (string) $user->id === $id;
}, ['guards' => ['customer']]);

Broadcast::connection('reverb')->channel('staff.{id}', function (User $user, string $id): bool {
    return $user->is_active && $user->isStaff() && (string) $user->id === $id;
}, ['guards' => ['web']]);
```

Il progetto usa due sessioni distinte. Per questo `RealtimeController::authenticate()` carica queste regole quando viene chiamato dagli endpoint protetti esistenti:

- Clienti: `POST /api/v1/customer/realtime/auth`, con middleware `auth:customer`, account cliente attivo, CSRF e rate limit.
- Staff: `POST /realtime/auth`, con sessione staff, verifica telefono e rate limit.

Il controller controlla anche che il nome richiesto sia esattamente quello dell'account corrente. Non serve aggiungere una seconda rotta `/broadcasting/auth`. Non disabilitare CSRF per far funzionare Echo: il client API esistente gestisce cookie, token e rinnovo dopo errore 419.

Il tracking pubblico ha un'autorizzazione separata: serve il token completo della spedizione e il tracking deve essere attivo. Il canale `tracking.<hash>` riceve solo aggiornamenti dell'ordine, senza contenuto della chat.

## 3. Evento pratico: messaggi e tracking

L'evento utilizzato è `app/Events/WorkspaceUpdated.php`, equivalente al ruolo di un `MessageSent`, ma condiviso da messaggi, ricevute di lettura e tracking:

```php
<?php

namespace App\Events;

use App\Models\Order;
use App\Models\User;
use App\UserRole;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

class WorkspaceUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public string $eventId;

    public int $tries = 5;

    public array $backoff = [1, 3, 10, 30];

    public function __construct(public int $orderId, public string $kind)
    {
        $this->eventId = (string) Str::uuid();
    }

    public function broadcastOn(): array
    {
        $order = Order::find($this->orderId);
        if (! $order) {
            return [];
        }

        $channels = User::where('is_active', true)->where(fn ($query) => $query->where('id', $order->customer_id)->orWhereIn('role', [UserRole::Admin, UserRole::Rider]))->get()->filter(fn (User $user) => $user->role === UserRole::Customer ? $order->customer_id === $user->id : $user->can('view', $order))->map(fn (User $user) => new PrivateChannel(($user->role === UserRole::Customer ? 'customer.' : 'staff.').$user->id))->values()->all();
        if ($this->kind === 'order' && $order->tracking_started_at) {
            $channels[] = new PrivateChannel('tracking.'.hash('sha256', $order->tracking_token));
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'workspace.updated';
    }

    public function broadcastWith(): array
    {
        return ['event_id' => $this->eventId, 'order_id' => $this->orderId, 'kind' => $this->kind];
    }
}
```

`broadcastOn()` seleziona i clienti e gli operatori attivi autorizzati all'ordine e restituisce i rispettivi `PrivateChannel`. Non sostituire questa selezione con un canale globale.

Dopo il salvataggio di un messaggio, `NotifyOrderParticipants` esegue già:

```php
WorkspaceUpdated::dispatch($order->id, 'messages');
```

Per le variazioni dell'ordine usa `kind = 'order'`; per le ricevute usa `receipts`. `ShouldDispatchAfterCommit` evita di annunciare transazioni annullate. `ShouldBroadcast` invia tramite la coda: serve un worker. Il payload contiene solo identificativi; il frontend rilegge le API, dove vengono nuovamente controllati i permessi.

## 4. Echo nel frontend React

Su un nuovo frontend:

```bash
npm install laravel-echo pusher-js
```

Nel nostro frontend sono già installati; usa `npm ci` per ripristinare il lockfile. Il `.env` React contiene:

```dotenv
VITE_API_URL=/api/v1/customer
BACKEND_URL=http://localhost:8000
```

Vite inoltra `/api` al backend. L'endpoint autenticato `/realtime/configuration` fornisce chiave pubblica, host, porta, schema e canale dell'account. Non è necessario duplicarli nell'ambiente React.

`src/services/workspace-realtime.js` inizializza Echo in questo modo:

```js
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const config = await request('/realtime/configuration');
const echo = new Echo({
  broadcaster: 'reverb',
  client: new Pusher(config.key, {
    cluster: 'mt1',
    wsHost: config.host,
    wsPort: config.port,
    wssPort: config.port,
    forceTLS: config.scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    disableStats: true,
    channelAuthorization: {
      customHandler: (params, callback) => {
        request('/realtime/auth', {
          method: 'POST',
          data: { socket_id: params.socketId, channel_name: params.channelName },
        }).then(data => callback(null, data)).catch(error => callback(error, null));
      },
    },
  }),
});

echo.private(config.channel).listen('.workspace.updated', event => {
  window.dispatchEvent(new CustomEvent('ea:workspace-updated', { detail: event }));
});
```

Il punto iniziale in `.workspace.updated` corrisponde al nome personalizzato di `broadcastAs()`. Il servizio reale gestisce anche eventi duplicati, riconnessione, sincronizzazione dopo la sottoscrizione e `echo.disconnect()` durante la pulizia. `pusher-js` è il client del protocollo: host e connessioni puntano a Reverb, senza bisogno di un account Pusher.

`PortalLayout` apre una sola connessione per l'account e la chiude allo smontaggio:

```jsx
useEffect(() => connectWorkspace(request), [user.id]);
```

Questo esempio React ascolta la sottoscrizione già aperta dal layout e aggiorna lo stato con i messaggi autorizzati:

```jsx
import { useEffect, useState } from 'react';
import { listMessages } from '../api/messages';

export default function LiveMessages({ orderId }) {
  const [messages, setMessages] = useState([]);
  const [error, setError] = useState(null);

  useEffect(() => {
    let disposed = false;
    let revision = 0;
    const reload = async () => {
      const current = ++revision;
      try {
        const result = await listMessages({ id: orderId, page: 1 });
        if (!disposed && current === revision) {
          setMessages(result.data);
          setError(null);
        }
      } catch (error) {
        if (!disposed && current === revision) setError(error);
      }
    };
    const updated = ({ detail }) => {
      if (detail.kind === 'sync' || Number(detail.order_id) === Number(orderId)) reload();
    };
    window.addEventListener('ea:workspace-updated', updated);
    reload();
    return () => {
      disposed = true;
      window.removeEventListener('ea:workspace-updated', updated);
    };
  }, [orderId]);

  return <>
    {error && <p role="alert">Impossibile aggiornare i messaggi.</p>}
    {messages.map(message => <p key={message.id}>{message.body}</p>)}
  </>;
}
```

Nel progetto `MessageThread` usa già `useApi(listMessages, { id, page }, true)`, che integra questo comportamento e la paginazione. Per i dettagli di tracking l'equivalente è `useApi(getOrder, { id }, true)`.

## 5. Avvio e test locale

Nel backend, applica le migrazioni pendenti e pulisci la cache di configurazione:

```bash
php artisan migrate --no-interaction
php artisan config:clear --no-interaction
npm run build
```

Avvia questi processi in terminali separati, dalla cartella backend:

```bash
php artisan serve --host=127.0.0.1 --port=8000 --no-interaction
php artisan reverb:start --host=127.0.0.1 --port=8080 --no-interaction
php artisan queue:work --tries=5 --timeout=60 --no-interaction
```

Dal frontend, in un quarto terminale:

```bash
npm run dev
```

Se i server sono già avviati, riutilizzali. Usa lo stesso hostname del frontend configurato nelle origini ammesse. Apri una sessione cliente e una staff assegnata alla spedizione, invia un messaggio e cambia lo stato dell'ordine: entrambi devono aggiornarsi senza ricaricare la pagina. In DevTools controlla la connessione WebSocket, l'autorizzazione `realtime/auth` con risposta 200 e l'evento `workspace.updated`. Un account diverso deve ricevere 403 per il canale altrui. La lettura delle notifiche aggiorna il badge sulla campanella.

Controlli automatici backend:

```bash
php artisan test --compact tests/Feature/RealtimeWorkspaceTest.php tests/Feature/CustomerExperienceTest.php
node --test tests/JavaScript/*.test.mjs
```

Frontend:

```bash
npm test
npm run lint
npm run build
```

Se la connessione fallisce, verifica Reverb, host/porta, schema e origini; con 401/419 controlla sessione e CSRF; con 403 controlla account e canale. Se la sottoscrizione riesce ma non arrivano eventi, controlla il worker e `php artisan queue:failed`. Dopo modifiche a codice/configurazione riavvia Reverb e il worker (`reverb:restart` e `queue:restart`, con un process manager che li rilanci in produzione). Usa `reverb:start --debug` solo con dati sintetici: può stampare il traffico.

Riferimenti ufficiali: [Broadcasting Laravel 13](https://laravel.com/framework/docs/13.x/broadcasting), [Reverb Laravel 13](https://laravel.com/framework/docs/13.x/reverb).
