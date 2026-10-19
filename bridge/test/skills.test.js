import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, mkdir, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { listSkills, parseFrontMatter } from '../src/skills.js';

test('skills are read from .larapilot/skills and the .claude mirror without duplicates', async () => {
  const dir = await mkdtemp(join(tmpdir(), 'studio-skills-'));
  await mkdir(join(dir, '.larapilot/skills/acme-gate'), { recursive: true });
  await writeFile(join(dir, '.larapilot/skills/acme-gate/SKILL.md'), '---\nname: acme-gate\ndescription: "GO or NO-GO before a release"\n---\n# body');
  await mkdir(join(dir, '.claude/skills/acme-gate'), { recursive: true });
  await writeFile(join(dir, '.claude/skills/acme-gate/SKILL.md'), '---\nname: acme-gate\ndescription: mirror\n---');
  await mkdir(join(dir, '.claude/skills/only-here'), { recursive: true });
  await writeFile(join(dir, '.claude/skills/only-here/SKILL.md'), '---\ndescription: no name line\n---');

  const skills = await listSkills(dir);
  assert.deepEqual(skills, [
    { name: 'acme-gate', description: 'GO or NO-GO before a release', source: '.larapilot/skills' },
    { name: 'only-here', description: 'no name line', source: '.claude/skills' },
  ]);
});

test('a workspace without skills yields an empty list', async () => {
  const dir = await mkdtemp(join(tmpdir(), 'studio-noskills-'));
  assert.deepEqual(await listSkills(dir), []);
});

test('front matter without fences is empty', () => {
  assert.deepEqual(parseFrontMatter('# just markdown'), {});
});

test('commands of the repository and the person\'s own skills and commands are listed too; the repository wins', async () => {
  const dir = await mkdtemp(join(tmpdir(), 'studio-skills-'));
  const home = await mkdtemp(join(tmpdir(), 'studio-claude-'));
  await mkdir(join(dir, '.claude/commands/release'), { recursive: true });
  await writeFile(join(dir, '.claude/commands/deploy-check.md'), '---\nargument-hint: "[environment]"\n---\n# Deploy check\n\nCheck that a deploy can go out.\n');
  await writeFile(join(dir, '.claude/commands/release/notes.md'), 'Write the release notes from the merged PRs.');
  await mkdir(join(home, 'skills/my-review'), { recursive: true });
  await writeFile(join(home, 'skills/my-review/SKILL.md'), '---\nname: my-review\ndescription: My own review checklist\n---');
  await mkdir(join(home, 'commands'), { recursive: true });
  await writeFile(join(home, 'commands/deploy-check.md'), 'Mine, hidden by the repository\'s');

  assert.deepEqual(await listSkills(dir, { claudeDir: home }), [
    { name: 'deploy-check', description: 'Check that a deploy can go out.', source: '.claude/commands', argument_hint: '[environment]' },
    { name: 'my-review', description: 'My own review checklist', source: '~/.claude/skills' },
    { name: 'release:notes', description: 'Write the release notes from the merged PRs.', source: '.claude/commands' },
  ]);
});
