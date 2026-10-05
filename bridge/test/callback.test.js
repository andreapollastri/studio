import test from 'node:test';
import assert from 'node:assert/strict';
import { CallbackPoster } from '../src/callback.js';
import { startCollector } from './helpers.js';

test('deltas are coalesced and an urgent event flushes at once, in order', async () => {
  const studio = await startCollector();
  const poster = new CallbackPoster({ url: studio.url, token: 'cb-token', conversation: 7, flushInterval: 50 });

  poster.push([{ type: 'text_delta', text: 'Ho ', index: 0 }]);
  poster.push([{ type: 'text_delta', text: 'letto', index: 0 }]);
  poster.push([{ type: 'text', text: 'Ho letto' }]);
  await poster.drain();

  assert.equal(studio.posts.length, 1);
  assert.equal(studio.posts[0].headers.authorization, 'Bearer cb-token');
  assert.equal(studio.posts[0].body.conversation, '7');
  assert.deepEqual(studio.posts[0].body.events, [
    { type: 'text_delta', text: 'Ho letto', index: 0 },
    { type: 'text', text: 'Ho letto' },
  ]);

  await studio.close();
});

test('a quiet delta goes out when the timer fires', async () => {
  const studio = await startCollector();
  const poster = new CallbackPoster({ url: studio.url, token: 't', conversation: 1, flushInterval: 10 });
  poster.push([{ type: 'text_delta', text: 'a', index: 0 }]);
  await new Promise((r) => setTimeout(r, 40));
  await poster.drain();
  assert.equal(studio.events().length, 1);
  await studio.close();
});
