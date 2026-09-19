import { readFileSync, writeFileSync, unlinkSync } from 'node:fs';
import { createConnection } from 'node:net';
import { parseEnv } from 'node:util';
import concurrently from 'concurrently';

const lockPath = 'storage/framework/dev-server.json';
let locked = false;
let stopping = false;
const controller = new AbortController();

function isRunning(pid) {
  if (!Number.isInteger(pid) || pid <= 0) return false;
  try { process.kill(pid, 0); return true; } catch { return false; }
}

function isListening(port) {
  return new Promise(resolve => {
    const socket = createConnection({ host: '127.0.0.1', port });
    const finish = value => { socket.destroy(); resolve(value); };
    socket.once('connect', () => finish(true));
    socket.once('error', () => finish(false));
    socket.setTimeout(500, () => finish(false));
  });
}

try {
  let previous;
  try { previous = JSON.parse(readFileSync(lockPath, 'utf8')); } catch { /* Primo avvio. */ }
  if (isRunning(previous?.pid)) {
    console.log('I servizi di sviluppo sono già avviati. Usa la sessione esistente; per riavviarli premi Ctrl+C in quella sessione e ripeti composer dev.');
  } else {
    if (previous) unlinkSync(lockPath);
    const env = { ...parseEnv(readFileSync('.env', 'utf8')), ...process.env };
    const serverPort = Number(env.SERVER_PORT || 8000);
    const reverbPort = Number(env.REVERB_SERVER_PORT || 8080);
    for (const port of [serverPort, reverbPort]) {
      if (!Number.isInteger(port) || port < 1 || port > 65535) throw new Error('Porta di sviluppo non valida nel file .env.');
      if (await isListening(port)) throw new Error(`La porta ${port} è già occupata. Chiudi la precedente istanza di Laravel/Reverb prima di eseguire composer dev.`);
    }
    writeFileSync(lockPath, JSON.stringify({ pid: process.pid }), { flag: 'wx', mode: 0o600 });
    locked = true;
    const stop = () => { stopping = true; controller.abort(); };
    process.once('SIGINT', stop);
    process.once('SIGTERM', stop);
    const { result } = concurrently([
      { name: 'server', command: `php artisan serve --port=${serverPort} --tries=1 --no-interaction` },
      { name: 'queue', command: 'php artisan queue:work --sleep=1 --tries=5 --timeout=60 --no-interaction' },
      { name: 'reverb', command: `php artisan reverb:start --host=127.0.0.1 --port=${reverbPort} --no-interaction` },
      { name: 'logs', command: 'php artisan pail --timeout=0 --no-interaction' },
      { name: 'vite', command: 'npm run dev' },
    ], { killOthersOn: ['success', 'failure'], abortSignal: controller.signal });
    await result;
  }
} catch (error) {
  if (!stopping) {
    console.error(error instanceof Error ? error.message : 'Un servizio si è arrestato. Controlla il messaggio con il suo nome riportato sopra.');
    process.exitCode = 1;
  }
} finally {
  if (locked) unlinkSync(lockPath);
}
