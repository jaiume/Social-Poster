import test from 'node:test';
import assert from 'node:assert/strict';
import { sleepMs, TYPE_DELAY_MS } from '../lib/timing.js';

test('sleepMs resolves', async () => {
  const start = Date.now();
  await sleepMs(50);
  assert.ok(Date.now() - start >= 40);
});

test('TYPE_DELAY_MS is zero for instant typing', () => {
  assert.equal(TYPE_DELAY_MS, 0);
});
