import test from 'node:test';
import assert from 'node:assert/strict';
import { NdjsonParser } from '../src/ndjson.js';

test('lines split across chunks are reassembled', () => {
  const lines = [];
  const parser = new NdjsonParser((line) => lines.push(line));
  parser.push('{"a":1}\n{"b":');
  parser.push('2}\n{"c"');
  parser.push(':3}');
  parser.end();
  assert.deepEqual(lines, [{ a: 1 }, { b: 2 }, { c: 3 }]);
});

test('a malformed line is reported and the stream goes on', () => {
  const lines = [];
  const bad = [];
  const parser = new NdjsonParser((line) => lines.push(line), (text) => bad.push(text));
  parser.push('{"ok":true}\n{not json}\n{"ok":2}\n');
  assert.deepEqual(lines, [{ ok: true }, { ok: 2 }]);
  assert.deepEqual(bad, ['{not json}']);
});

test('very large lines are fine', () => {
  const lines = [];
  const parser = new NdjsonParser((line) => lines.push(line));
  const big = 'x'.repeat(2 * 1024 * 1024);
  parser.push(JSON.stringify({ big }) + '\n');
  assert.equal(lines[0].big.length, big.length);
});
