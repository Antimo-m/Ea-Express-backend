import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
export function connectWorkspace(request) {
  let stopped = false, echo;
  const seen = new Set();
  const status = (state) => window.dispatchEvent(new CustomEvent('ea:realtime-status', { detail: state }));
  request('/realtime/configuration').then(config => {
    if (stopped || !config.key) return;
    echo = new Echo({ broadcaster: 'reverb', client: new Pusher(config.key, {
      cluster: 'mt1', wsHost: config.host, wsPort: config.port, wssPort: config.port,
      forceTLS: config.scheme === 'https', enabledTransports: ['ws', 'wss'], disableStats: true,
      channelAuthorization: { customHandler: (params, callback) => {
        request('/realtime/auth', { method: 'POST', data: { socket_id: params.socketId, channel_name: params.channelName } }).then(data => callback(null, data)).catch(error => callback(error, null));
      } },
    }) });
    echo.connector.pusher.connection.bind('state_change', ({ current }) => status(current));
    echo.private(config.channel).subscribed(() => {
      status('connected');
      window.dispatchEvent(new CustomEvent('ea:workspace-updated', { detail: { kind: 'sync' } }));
    }).listen('.workspace.updated', event => {
      if (seen.has(event.event_id)) return;
      seen.add(event.event_id);
      if (seen.size > 1000) seen.delete(seen.values().next().value);
      window.dispatchEvent(new CustomEvent('ea:workspace-updated', { detail: event }));
    }).error(() => status('unavailable'));
  }).catch(() => status('unavailable'));
  return () => { stopped = true; echo?.disconnect(); };
}
