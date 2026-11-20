import test from 'node:test';
import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import { labelForId, listModels, parseAnthropicModels, parseCursorModels } from '../src/models.js';

test('Cursor model lines are parsed as id + hint', () => {
  assert.deepEqual(parseCursorModels(`
Available models

composer-2 - Cursor's coding model
claude-opus-5-5 - Anthropic
gpt-5.6-sol - OpenAI
not a model line
`), [
    { id: 'composer-2', label: 'Composer 2', hint: "Cursor's coding model" },
    { id: 'claude-opus-5-5', label: 'Opus 5.5', hint: 'Anthropic' },
    { id: 'gpt-5.6-sol', label: 'Gpt 5.6 sol', hint: 'OpenAI' },
  ]);
});

test('Anthropic Models API rows keep display names and skip non-claude ids', () => {
  assert.deepEqual(parseAnthropicModels({
    data: [
      { id: 'claude-sonnet-5-5', display_name: 'Claude Sonnet 5.5', description: 'Balanced\nmore' },
      { id: 'claude-opus-5-5' },
      { id: 'some-other-model' },
      { id: 'claude-embed-v1' },
    ],
  }), [
    { id: 'claude-sonnet-5-5', label: 'Claude Sonnet 5.5', hint: 'Balanced' },
    { id: 'claude-opus-5-5', label: 'Opus 5.5' },
  ]);
});

test('Claude without a key returns Default plus aliases', async () => {
  const models = await listModels({ agent: 'claude' });
  assert.equal(models[0].id, '');
  assert.equal(models[0].label, 'Default');
  assert.deepEqual(models.slice(1).map((m) => m.id), ['fable', 'opus', 'sonnet', 'haiku']);
});

test('Claude with an API key prepends aliases to the live list', async () => {
  const models = await listModels({ agent: 'claude', apiKey: 'sk-test' }, {
    fetch: async () => ({
      ok: true,
      async json() {
        return { data: [{ id: 'claude-haiku-5-5', display_name: 'Haiku 5.5' }] };
      },
    }),
  });

  assert.deepEqual(models.map((m) => m.id), ['', 'fable', 'opus', 'sonnet', 'haiku', 'claude-haiku-5-5']);
  assert.equal(models.at(-1).label, 'Haiku 5.5');
});

test('Cursor lists models through the agent CLI for that subscription folder', async () => {
  const seen = [];
  const models = await listModels({ agent: 'cursor', cursorDir: '.cursor-work' }, {
    cursorBin: 'agent',
    home: '/tmp/home',
    spawn: (bin, args, opts) => {
      seen.push({ bin, args, env: opts.env });
      const child = new EventEmitter();
      child.stdout = new EventEmitter();
      child.stderr = new EventEmitter();
      child.kill = () => {};
      queueMicrotask(() => {
        child.stdout.emit('data', 'composer-2 - Fast\nclaude-sonnet-5-5 - Balanced\n');
        child.emit('close', 0);
      });
      return child;
    },
  });

  assert.equal(seen[0].bin, 'agent');
  assert.deepEqual(seen[0].args, ['models']);
  assert.equal(seen[0].env.CURSOR_CONFIG_DIR, '/tmp/home/.cursor-work');
  assert.deepEqual(models.map((m) => m.id), ['', 'composer-2', 'claude-sonnet-5-5']);
  assert.equal(models[0].label, 'Auto');
});

test('labelForId covers Claude and Cursor ids', () => {
  assert.equal(labelForId('claude-fable-5-2'), 'Fable 5.2');
  assert.equal(labelForId('opus'), 'Opus (latest)');
  assert.equal(labelForId('composer-2'), 'Composer 2');
});
