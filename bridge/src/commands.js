import { spawn } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { StringDecoder } from 'node:string_decoder';

/**
 * The project terminal: a short list of binaries, each with the subcommands a
 * person may run from the dashboard, and arguments that cannot reach a shell.
 * Nothing here goes through `sh`; the command is split into words and the
 * binary is executed directly in the workspace directory.
 */
const ARG = /^[A-Za-z0-9@:_.\/=+,%-]+$/;
const MAX_ARGS = 24;

const RULES = {
  php: {
    first: ['artisan'],
    deny: [/^artisan$/, /^artisan\s+(tinker|serve|db:wipe|migrate:fresh\s.*--force|down)\b/],
  },
  composer: { first: ['install', 'update', 'dump-autoload', 'show', 'outdated', 'validate', 'audit', 'require', 'remove', 'run-script', 'run', 'test', 'lint'] },
  npm: { first: ['ci', 'install', 'run', 'test', 'outdated', 'audit', 'ls'] },
  git: {
    first: ['status', 'log', 'diff', 'branch', 'fetch', 'pull', 'checkout', 'switch', 'stash', 'show', 'add', 'commit', 'push', 'restore', 'remote', 'tag', '--version'],
    deny: [/\s--force\b/, /\s-f\b/, /^push\s.*\+/, /^(branch|tag)\s+-[dD]\b/],
  },
  node: { first: ['--version', '-v'] },
  vendor: {},
};

export function parseCommand(input) {
  const text = String(input ?? '').trim();
  if (text === '' || text.length > 500) throw new CommandError('The command is empty or too long.');
  const words = text.split(/\s+/);
  if (words.length > MAX_ARGS) throw new CommandError('Too many arguments.');
  for (const word of words) {
    if (!ARG.test(word)) throw new CommandError(`"${word}" is not allowed: only plain words, paths and options, no quotes, pipes or redirections.`);
  }

  let [bin, ...args] = words;
  // vendor/bin/pest, vendor/bin/pint, vendor/bin/phpstan
  if (bin.startsWith('vendor/bin/')) {
    const tool = bin.slice('vendor/bin/'.length);
    if (!['pest', 'pint', 'phpstan', 'phpunit', 'rector'].includes(tool)) throw new CommandError(`${bin} is not in the list.`);
    return { bin: 'php', args: [bin, ...withColor(tool, args)], display: text };
  }

  const rule = RULES[bin];
  if (!rule || bin === 'vendor') throw new CommandError(`${bin} is not in the list. Allowed: php artisan, composer, npm, git, vendor/bin/{pest,pint,phpstan}.`);
  if (rule.first && !rule.first.includes(args[0] ?? '')) throw new CommandError(`${bin} ${args[0] ?? ''} is not in the list.`);
  const rest = args.join(' ');
  for (const pattern of rule.deny ?? []) {
    if (pattern.test(rest)) throw new CommandError(`${text} is not allowed from the terminal.`);
  }
  return { bin, args: withColor(bin, args), display: text };
}

/**
 * Output is piped, not a TTY, so most tools would print plain text; the pane
 * renders ANSI colours, so ask for them unless the person chose otherwise.
 * Symfony Console takes --ansi before the command name, PHPUnit and Pest
 * --colors=always; git gets color.ui through the environment (see start).
 */
function withColor(tool, args) {
  if (args.some((arg) => /^--(no-)?ansi$|^--colors(=|$)/.test(arg))) return args;
  if (tool === 'pest' || tool === 'phpunit') return ['--colors=always', ...args];
  if (tool === 'composer' || tool === 'pint' || tool === 'phpstan' || tool === 'rector') return ['--ansi', ...args];
  if (tool === 'php') return [args[0], '--ansi', ...args.slice(1)];
  return args;
}

function withoutNoColor(env) {
  const { NO_COLOR, ...rest } = env;
  return rest;
}

export class CommandError extends Error {
  status = 422;
}

/** Keeps the last runs in memory; output is bounded, a run is killed after the timeout. */
export class RunManager {
  #runs = new Map();
  #config;
  #spawn;

  constructor(config, { spawn: spawnImpl = spawn } = {}) {
    this.#config = config;
    this.#spawn = spawnImpl;
  }

  /** @param {{cwd?: string}} options a sandbox's worktree instead of the workspace */
  start(input, { cwd = null } = {}) {
    const { bin, args, display } = parseCommand(input);
    const id = randomUUID();
    const run = { id, command: display, status: 'running', exit_code: null, output: '', started_at: new Date().toISOString(), finished_at: null, truncated: false };
    this.#runs.set(id, run);
    this.#trim();

    const child = this.#spawn(bin, args, {
      cwd: cwd ?? this.#config.workspaceDir,
      env: {
        ...withoutNoColor(process.env),
        CI: '1',
        TERM: 'dumb',
        FORCE_COLOR: '1',
        GIT_CONFIG_COUNT: '1',
        GIT_CONFIG_KEY_0: 'color.ui',
        GIT_CONFIG_VALUE_0: 'always',
        COMPOSER_NO_INTERACTION: '1',
      },
      stdio: ['ignore', 'pipe', 'pipe'],
    });
    run.child = child;

    const limit = this.#config.runOutputLimit ?? 200 * 1024;
    const append = (text) => {
      if (text === '') return;
      if (run.output.length >= limit) { run.truncated = true; return; }
      run.output += text.slice(0, limit - run.output.length);
    };
    // One decoder per stream, so a character split across two chunks stays whole.
    for (const stream of [child.stdout, child.stderr]) {
      const decoder = new StringDecoder('utf8');
      stream.on('data', (chunk) => append(decoder.write(chunk)));
      stream.on('end', () => append(decoder.end()));
    }

    const timer = setTimeout(() => {
      if (run.status === 'running') {
        run.output += `\n[killed after ${this.#config.runTimeout ?? 600}s]\n`;
        try { child.kill('SIGKILL'); } catch {}
      }
    }, (this.#config.runTimeout ?? 600) * 1000);
    timer.unref?.();

    child.on('error', (error) => {
      run.output += `\n${error.message}\n`;
      finish(null);
    });
    child.on('close', (code) => finish(code));

    const finish = (code) => {
      if (run.status !== 'running') return;
      clearTimeout(timer);
      run.status = code === 0 ? 'done' : 'failed';
      run.exit_code = code;
      run.finished_at = new Date().toISOString();
      delete run.child;
    };

    return this.snapshot(run);
  }

  get(id) {
    const run = this.#runs.get(id);
    return run ? this.snapshot(run) : null;
  }

  kill(id) {
    const run = this.#runs.get(id);
    if (!run) return null;
    if (run.status === 'running' && run.child) {
      try { run.child.kill('SIGTERM'); } catch {}
    }
    return this.snapshot(run);
  }

  list() {
    return [...this.#runs.values()].map((run) => this.snapshot(run)).reverse();
  }

  snapshot(run) {
    const { child, ...rest } = run;
    return rest;
  }

  #trim() {
    const max = this.#config.runHistory ?? 20;
    while (this.#runs.size > max) {
      const oldest = this.#runs.keys().next().value;
      const run = this.#runs.get(oldest);
      if (run?.status === 'running') break;
      this.#runs.delete(oldest);
    }
  }
}
