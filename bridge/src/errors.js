/** An answer for Studio: the HTTP status and why. */
export class BridgeError extends Error {
  constructor(status, message) {
    super(message);
    this.status = status;
  }
}
