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

  list() {
    return [...this.#sessions.values()].map((session) => session.snapshot());
  }

  async closeAll() {
    await Promise.all([...this.#sessions.values()].map((session) => session.close()));
    this.#sessions.clear();
  }
}
