import { readdir, readFile, stat } from 'node:fs/promises';
import { join } from 'node:path';

/**
 * Custom skills the repository ships, so Studio can show them as buttons.
 * Larapilot keeps them in `.larapilot/skills/<name>/SKILL.md`; Claude Code
 * reads the mirror in `.claude/skills`. Both are scanned, names deduplicated.
 */
export async function listSkills(workspaceDir) {
  const roots = [
    { dir: join(workspaceDir, '.larapilot', 'skills'), source: '.larapilot/skills' },
    { dir: join(workspaceDir, '.claude', 'skills'), source: '.claude/skills' },
  ];
  const seen = new Map();

  for (const root of roots) {
    let entries = [];
    try {
      entries = await readdir(root.dir);
    } catch {
      continue;
    }

    for (const entry of entries) {
      const file = join(root.dir, entry, 'SKILL.md');
      try {
        if (!(await stat(file)).isFile()) continue;
      } catch {
        continue;
      }
      const front = parseFrontMatter(await readFile(file, 'utf8'));
      const name = (front.name || entry).trim();
      if (!name || seen.has(name)) continue;
      seen.set(name, { name, description: (front.description || '').trim(), source: root.source });
    }
  }

  return [...seen.values()].sort((a, b) => a.name.localeCompare(b.name));
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
