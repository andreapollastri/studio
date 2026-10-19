import { readdir, readFile, stat } from 'node:fs/promises';
import { join } from 'node:path';

/**
 * What a slash command in the chat can start, as Claude Code finds it: the
 * repository's skills (Larapilot keeps them in `.larapilot/skills/<name>/SKILL.md`,
 * Claude Code reads the mirror in `.claude/skills`) and commands
 * (`.claude/commands/**.md`, a folder making `folder:name`), then the person's own
 * in their Claude folder. The repository's win on the same name.
 */
export async function listSkills(workspaceDir, { claudeDir = null } = {}) {
  const roots = [
    { dir: join(workspaceDir, '.larapilot', 'skills'), source: '.larapilot/skills', kind: 'skill' },
    { dir: join(workspaceDir, '.claude', 'skills'), source: '.claude/skills', kind: 'skill' },
    { dir: join(workspaceDir, '.claude', 'commands'), source: '.claude/commands', kind: 'command' },
    ...(claudeDir ? [
      { dir: join(claudeDir, 'skills'), source: '~/.claude/skills', kind: 'skill' },
      { dir: join(claudeDir, 'commands'), source: '~/.claude/commands', kind: 'command' },
    ] : []),
  ];
  const seen = new Map();

  for (const root of roots) {
    const found = root.kind === 'skill' ? await skillsIn(root.dir) : await commandsIn(root.dir);
    for (const { name, markdown } of found) {
      const front = parseFrontMatter(markdown);
      const skill = (front.name || name).trim();
      if (!skill || seen.has(skill)) continue;
      seen.set(skill, {
        name: skill,
        description: (front.description || firstLine(markdown)).trim(),
        source: root.source,
        ...(front['argument-hint'] ? { argument_hint: front['argument-hint'] } : {}),
      });
    }
  }

  return [...seen.values()].sort((a, b) => a.name.localeCompare(b.name));
}

/**
 * <dir>/<name>/SKILL.md, and skills a folder down or two (~/.claude/skills/synced/<name>/SKILL.md,
 * the skills Claude Code syncs from the person's account): a folder with a SKILL.md is a skill
 * and is not looked into further.
 */
async function skillsIn(dir, depth = 0) {
  const out = [];
  for (const entry of await entries(dir)) {
    if (entry.startsWith('.') || entry === 'node_modules') continue;
    const path = join(dir, entry);
    try {
      if (!(await stat(path)).isDirectory()) continue;
    } catch {
      continue;
    }
    const file = join(path, 'SKILL.md');
    let isSkill = false;
    try {
      isSkill = (await stat(file)).isFile();
    } catch {
      /* not a skill itself */
    }
    if (isSkill) out.push({ name: entry, markdown: await readFile(file, 'utf8') });
    else if (depth < 2) out.push(...(await skillsIn(path, depth + 1)));
  }
  return out;
}

/** <dir>/**.md, a subfolder giving folder:name, two levels deep at most */
async function commandsIn(dir, prefix = '', depth = 0) {
  const out = [];
  for (const entry of await entries(dir)) {
    const path = join(dir, entry);
    let info;
    try {
      info = await stat(path);
    } catch {
      continue;
    }
    if (info.isDirectory() && depth < 2 && !entry.startsWith('.')) {
      out.push(...(await commandsIn(path, `${prefix}${entry}:`, depth + 1)));
    } else if (info.isFile() && entry.endsWith('.md')) {
      out.push({ name: `${prefix}${entry.slice(0, -3)}`, markdown: await readFile(path, 'utf8') });
    }
  }
  return out;
}

async function entries(dir) {
  try {
    return (await readdir(dir)).sort();
  } catch {
    return [];
  }
}

/** A command without a description: its first line of text. */
function firstLine(markdown) {
  const body = markdown.replace(/^---\s*\n[\s\S]*?\n---\s*\n?/, '');
  const line = body.split('\n').map((l) => l.trim()).find((l) => l !== '' && !l.startsWith('#'));
  return (line ?? '').slice(0, 200);
}

/** Minimal YAML front matter reader: `key: value` lines between --- fences, quotes stripped. */
export function parseFrontMatter(markdown) {
  const match = /^---\s*\n([\s\S]*?)\n---/.exec(markdown);
  if (!match) return {};
  const data = {};
  for (const line of match[1].split('\n')) {
    const m = /^([A-Za-z0-9_-]+):\s*(.*)$/.exec(line);
    if (!m) continue;
    let value = m[2].trim();
    if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
      value = value.slice(1, -1);
    }
    data[m[1]] = value;
  }
  return data;
}
