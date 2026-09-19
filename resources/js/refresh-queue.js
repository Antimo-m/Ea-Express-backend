export function createRefreshQueue(load, onError = () => {}) {
  let pending = false;
  let running;
  let disposed = false;

  return {
    refresh() {
      if (disposed) return Promise.resolve();
      pending = true;
      if (running) return running;
      running = (async () => {
        while (pending && !disposed) {
          pending = false;
          try { await load(); } catch (error) { onError(error); }
        }
      })().finally(() => { running = undefined; });
      return running;
    },
    dispose() { disposed = true; pending = false; },
  };
}
