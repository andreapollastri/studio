import { chmodSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { BROWSER_SERVER } from './browser.js';

/**
 * What Studio adds to Claude Code: a few subagents of its own (--agents) and a
 * paragraph of context (--append-system-prompt) about where the agent runs —
 * the preview it can look at, the sandbox it works in. The repository's own
 * .claude/agents still apply, and win on a name they share.
 */
export function studioAgents({ browser = null } = {}) {
  const agents = {
    'code-reviewer': {
      description: 'Reviews the changes in the working tree (git diff) for bugs, missed edge cases and inconsistencies with the surrounding code, and reports them ranked by severity. Use it before declaring a non-trivial change done, or in parallel while you go on with something else.',
      prompt: [
        'You review code changes in a Laravel project. Read `git status` and `git diff HEAD`, then the code around each change.',
        'Look for: bugs and wrong conditions, unhandled null or empty cases, missing authorization or validation, N+1 queries, broken migrations, tests that no longer match, code that does not read like its neighbours.',
        'Do not edit files. Answer with a list of findings, most severe first, each with file:line, what goes wrong and a concrete fix. Say plainly when you found nothing worth fixing.',
      ].join('\n'),
      tools: ['Read', 'Grep', 'Glob', 'Bash'],
    },
  };

  if (browser) {
    agents['browser-qa'] = {
      description: `Checks the running app in the browser like a tester would: opens pages of ${browser.url}, clicks through a flow, fills forms, takes screenshots and reads the console and failed network requests. Use it after UI changes, to reproduce a visual bug, or to verify several pages in parallel (one browser-qa per flow).`,
      prompt: [
        `You test a web app in a real browser through the \`${BROWSER_SERVER}\` MCP tools. The app runs at ${browser.url} and the browser is already signed in to it.`,
        'Work like a careful QA engineer: navigate, read the accessibility snapshot to find elements, act, then take a screenshot (browser_take_screenshot) of every state that matters. Check browser_console_messages and browser_network_requests for errors on each page.',
        'Do not edit files. Report: what you checked, what works, what is broken (with the URL, the steps, the console or network error), and mention the screenshots you took. Keep it short and factual.',
      ].join('\n'),
    };
  }

  return agents;
}

/** The agents in a file only this user reads: --agents takes a path with --print. */
export function writeAgentsFile(key, agents) {
  const file = join(tmpdir(), `studio-agents-${String(key).replace(/[^A-Za-z0-9_-]/g, '_')}.json`);
  writeFileSync(file, JSON.stringify(agents), { mode: 0o600 });
  chmodSync(file, 0o600);
  return file;
}

/**
 * @param {{browser?: {url: string}|null, sandbox?: {branch: string|null, dir: string}|null}} context
 */
export function studioPrompt({ browser = null, sandbox = null } = {}) {
  const lines = ['You are running inside Studio, a web app where the person follows this conversation, the preview of the app and its git changes.'];

  if (sandbox) {
    lines.push(
      `This conversation works in its own sandbox: a git worktree at ${sandbox.dir}${sandbox.branch ? ` on branch \`${sandbox.branch}\`` : ''}, with its own database and its own preview, apart from the person's main workspace.`,
      'Stay on this branch and commit your work on it when a step is done (the person merges the branch from Studio). Do not check out other branches here and do not touch the main workspace directory.',
    );
  }

  if (browser) {
    lines.push(
      `The app in your working directory is served at ${browser.url}. You have a browser for it: the \`${BROWSER_SERVER}\` MCP tools (mcp__${BROWSER_SERVER}__*), already signed in.`,
      'After a change that shows in the UI, look at it before saying it is done: navigate to the page, take a screenshot (browser_take_screenshot) and check the console for errors. The person sees your screenshots in the chat, so take one of the final result.',
    );
  }

  lines.push('Independent work can run in parallel with subagents (the Task tool): for example `code-reviewer` on the diff' + (browser ? ' and one `browser-qa` per flow to check' : '') + ', while you go on.');

  return lines.join('\n');
}
