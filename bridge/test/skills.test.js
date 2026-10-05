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
