/**
 * Splits a byte stream into complete lines and parses each as JSON. Lines can
 * be very large (a hook payload, a tool result) and a crash can leave a half
 * line: that one is reported as malformed and skipped, nothing else stops.
 */
export class NdjsonParser {
  #buffer = '';
  #onLine;
  #onMalformed;

  constructor(onLine, onMalformed = () => {}) {
    this.#onLine = onLine;
    this.#onMalformed = onMalformed;
  }

  push(chunk) {
    this.#buffer += chunk;
    let index;
    while ((index = this.#buffer.indexOf('\n')) !== -1) {
      const line = this.#buffer.slice(0, index);
      this.#buffer = this.#buffer.slice(index + 1);
      this.#emit(line);
    }
  }

  /** Flush whatever is left when the stream ends. */
  end() {
    if (this.#buffer.trim() !== '') this.#emit(this.#buffer);
    this.#buffer = '';
  }

  #emit(line) {
    const trimmed = line.trim();
    if (trimmed === '') return;
    try {
      this.#onLine(JSON.parse(trimmed), trimmed);
    } catch (error) {
      this.#onMalformed(trimmed, error);
    }
  }
}
