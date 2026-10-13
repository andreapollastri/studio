import { Session } from './session.js';

export class SessionManager {
  #sessions = new Map();
  #config;
  #options;

  constructor(config, options = {}) {
    this.#config = config;
    this.#options = options;
  }

  get(key) {
    return this.#sessions.get(String(key)) ?? null;
  }

  getOrCreate(key) {
    key = String(key);
    let session = this.#sessions.get(key);
    if (!session) {
      session = new Session(key, this.#config, this.#options);
      this.#sessions.set(key, session);
    }
    return session;
  }

  /** The conversation was deleted in Studio: stop its Claude Code and forget it. */
  async forget(key) {
    key = String(key);
    const session = this.#sessions.get(key);
    if (!session) return false;
    this.#sessions.delete(key);
    await session.close();
    return true;
  }

  /** A sandbox is going away: whatever runs in it stops (the conversations stay, idle). */
  async stopIn(dir) {
    for (const session of this.#sessions.values()) {
      if (session.cwd === dir) session.interrupt();
    }
  }

  list() {
    return [...this.#sessions.values()].map((session) => session.snapshot());
  }

  async closeAll() {
    await Promise.all([...this.#sessions.values()].map((session) => session.close()));
    this.#sessions.clear();
  }
}
