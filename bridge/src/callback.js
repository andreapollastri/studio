/**
 * Posts events to Studio in small batches. Consecutive text deltas are
 * merged into one; anything else flushes at once so a permission request or
 * a result never waits behind a timer.
 */
export class CallbackPoster {
  #url;
  #token;
  #conversation;
  #queue = [];
  #timer = null;
  #flushInterval;
  #inflight = Promise.resolve();
  #log;

  constructor({ url, token, conversation, flushInterval = 200, log = () => {} }) {
    this.#url = url;
    this.#token = token;
    this.#conversation = String(conversation);
    this.#flushInterval = flushInterval;
    this.#log = log;
  }

  push(events) {
    for (const event of events) {
      const last = this.#queue[this.#queue.length - 1];
      if (event.type === 'text_delta' && last && last.type === 'text_delta' && last.index === event.index) {
        last.text += event.text;
        continue;
      }
      this.#queue.push(event);
    }

    const urgent = events.some((event) => event.type !== 'text_delta' && event.type !== 'status');
    if (urgent) {
      this.flush();
    } else if (!this.#timer) {
      this.#timer = setTimeout(() => this.flush(), this.#flushInterval);
    }
  }

  flush() {
    if (this.#timer) {
      clearTimeout(this.#timer);
      this.#timer = null;
    }
    if (this.#queue.length === 0) return this.#inflight;

    const events = this.#queue;
    this.#queue = [];

    // Posts are serialised so Studio sees events in the order they happened.
    this.#inflight = this.#inflight.then(() => this.#post(events));
    return this.#inflight;
  }

  /** Resolve once everything queued so far has been delivered (or given up on). */
  drain() {
    return this.flush();
  }

  async #post(events, attempt = 1) {
    try {
      const response = await fetch(this.#url, {
        method: 'POST',
        headers: {
          'content-type': 'application/json',
          accept: 'application/json',
          authorization: `Bearer ${this.#token}`,
        },
        body: JSON.stringify({ conversation: this.#conversation, events }),
      });

      if (!response.ok) {
        const body = await response.text().catch(() => '');
        throw new Error(`Studio answered ${response.status}: ${body.slice(0, 200)}`);
      }
    } catch (error) {
      if (attempt < 3) {
        await new Promise((r) => setTimeout(r, 250 * attempt));
        return this.#post(events, attempt + 1);
      }
      this.#log(`callback failed after ${attempt} attempts: ${error.message}`);
    }
  }
}
