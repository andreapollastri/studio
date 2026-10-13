import { createHmac } from 'node:crypto';

/**
 * The gateway reads Studio's own .env (systemd's EnvironmentFile): the token it
 * shows Studio is derived from APP_KEY, so there is no second secret to keep.
 */
export function loadConfig(env = process.env) {
  const token = env.SSH_GATEWAY_TOKEN || (env.APP_KEY ? gatewayToken(env.APP_KEY) : '');
  if (!token) throw new Error('APP_KEY (or SSH_GATEWAY_TOKEN) is required.');

  return {
    host: env.SSH_GATEWAY_HOST || '127.0.0.1',
    port: Number(env.SSH_GATEWAY_PORT || 8082),
    redeemUrl: env.SSH_GATEWAY_REDEEM_URL || 'http://127.0.0.1:8081/api/ssh/redeem',
    token,
    // The page that may open sessions; empty accepts any origin (development).
    origin: env.SSH_GATEWAY_ORIGIN ?? originOf(env.APP_URL),
    maxPerUser: Number(env.SSH_GATEWAY_MAX_PER_USER || 12),
    readyTimeout: Number(env.SSH_GATEWAY_READY_TIMEOUT || 20) * 1000,
    hostKeyTimeout: Number(env.SSH_GATEWAY_HOST_KEY_TIMEOUT || 120) * 1000,
    log: env.SSH_GATEWAY_LOG !== '0',
    // The person's `claude auth login` goes through the privileged helper, as their Linux user.
    loginCommand: loginCommand(env.SSH_GATEWAY_HELPER || '/usr/local/sbin/studio-admin', env.SSH_GATEWAY_HELPER_SUDO !== '0'),
    loginTimeout: Number(env.SSH_GATEWAY_LOGIN_TIMEOUT || 600) * 1000,
  };
}

/** The same value as App\Ssh\Gateway::token() on the PHP side. */
export function gatewayToken(appKey) {
  return createHmac('sha256', appKey).update('studio-ssh-gateway').digest('hex');
}

function originOf(url) {
  try {
    return url ? new URL(url).origin : '';
  } catch {
    return '';
  }
}

function loginCommand(helper, sudo) {
  // dir: the config folder of one of the person's Claude accounts (.claude, .claude-<slug>); the default one goes unsaid
  return (handle, cols, rows, dir = '.claude') => [...(sudo ? ['sudo', '-n', helper] : [helper]), 'claude-login', handle, String(cols), String(rows), ...(dir === '.claude' ? [] : [dir])];
}
