/**
 * Trades the one-time ticket the page got from Studio for the connection it
 * stands for: host, user, and the private key or password. Only the gateway
 * ever sees the secret; the browser holds a ticket that is already spent.
 */
export async function redeem(config, ticket) {
  const response = await fetch(config.redeemUrl, {
    method: 'POST',
    headers: { authorization: `Bearer ${config.token}`, 'content-type': 'application/json', accept: 'application/json' },
    body: JSON.stringify({ ticket }),
    signal: AbortSignal.timeout(10_000),
  });

  if (response.status === 404 || response.status === 410) return null;
  if (!response.ok) throw new Error(`Studio answered ${response.status} to the ticket.`);

  return response.json();
}
