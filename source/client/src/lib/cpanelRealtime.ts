import { api, ApiError } from './api';
type Handler = (...args: any[]) => void;
/** Socket-compatible event interface backed by short PHP requests. No daemon required. */
export class Socket {
  private listeners = new Map<string, Set<Handler>>();
  private stopped = false;
  private cursor: number | null = null;
  private timer: number | undefined;
  private controller: AbortController | undefined;
  private connected = false;
  private connection = crypto.randomUUID().replaceAll('-', '');
  private typingTimers = new Map<string, number>();
  constructor() { this.timer = window.setTimeout(() => void this.poll(), 0); }
  on(event: string, handler: Handler): this {
    const handlers = this.listeners.get(event) ?? new Set<Handler>();
    handlers.add(handler); this.listeners.set(event, handlers); return this;
  }
  off(event: string, handler: Handler): this { this.listeners.get(event)?.delete(handler); return this; }
  private dispatch(event: string, payload?: unknown) {
    if (this.stopped) return;
    if (event === 'typing:start') {
      const p = payload as { userId: string; channelId?: string; conversationId?: string };
      const key = `${p.userId}:${p.channelId ?? p.conversationId}`;
      window.clearTimeout(this.typingTimers.get(key));
      this.typingTimers.set(key, window.setTimeout(() => { this.dispatch('typing:stop', payload); this.typingTimers.delete(key); }, 6500));
    }
    for (const handler of this.listeners.get(event) ?? []) {
      try { handler(payload); } catch (error) { console.error('Realtime listener failed', error); }
    }
  }
  private async poll() {
    if (this.stopped) return;
    let delay = document.hidden ? 2500 : 900;
    this.controller = new AbortController();
    const timeout = window.setTimeout(() => this.controller?.abort(), 15000);
    try {
      const query = new URLSearchParams({ connection: this.connection });
      if (this.cursor !== null) query.set('cursor', String(this.cursor));
      const data = await api.get<{ cursor: number; events: { name: string; payload: unknown }[]; voice: Record<string, unknown[]>; more: boolean }>(`/api/realtime?${query}`, { signal: this.controller.signal });
      if (this.stopped) return;
      this.cursor = data.cursor;
      if (!this.connected) { this.connected = true; this.dispatch('connect'); this.dispatch('ready', { voice: data.voice }); }
      for (const event of data.events) this.dispatch(event.name, event.payload);
      if (data.more) delay = 0;
    } catch (error) {
      if (this.stopped) return;
      if (this.connected) { this.connected = false; this.dispatch('disconnect'); }
      if (error instanceof ApiError && (error.status === 401 || error.status === 403)) { this.close(); return; }
      delay = 4000;
    } finally { window.clearTimeout(timeout); }
    if (!this.stopped) this.timer = window.setTimeout(() => void this.poll(), delay);
  }
  emit(event: string, ...args: unknown[]): this {
    const ack = args.find((arg) => typeof arg === 'function') as Handler | undefined;
    if (this.stopped) { ack?.({ ok: false, error: 'Connection closed.' }); return this; }
    void api.post<Record<string, unknown>>('/api/realtime', { event, payload: typeof args[0] === 'object' ? args[0] : {}, connection: this.connection })
      .then((result) => { if (!this.stopped) ack?.(result); })
      .catch((error) => ack?.({ ok: false, error: error instanceof Error ? error.message : 'Request failed.' }));
    return this;
  }
  close(): this {
    if (this.stopped) return this;
    this.emit('connection:close'); this.stopped = true;
    this.controller?.abort(); window.clearTimeout(this.timer);
    for (const timer of this.typingTimers.values()) window.clearTimeout(timer);
    this.typingTimers.clear(); this.connected = false; this.listeners.clear(); return this;
  }
}
export function io(_options?: unknown): Socket { return new Socket(); }
