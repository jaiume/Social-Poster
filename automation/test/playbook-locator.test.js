import test from 'node:test';
import assert from 'node:assert/strict';
import { probeLocator, resolveLocator } from '../lib/playbook-locator.js';

test('probeLocator reports invalid spec', async () => {
  const page = {};
  const result = await probeLocator(page, null, 'page', 'open_composer');
  assert.match(result, /invalid locator spec/i);
});

test('probeLocator rejects legacy kind specs', async () => {
  const page = {};
  const result = await probeLocator(
    page,
    { kind: 'role', role: 'button', name: 'Post' },
    'page',
    'open_composer'
  );
  assert.match(result, /legacy kind locator is not supported/i);
});

test('resolveLocator returns null for legacy kind specs', () => {
  assert.equal(resolveLocator({}, { kind: 'role', role: 'button' }), null);
});

test('resolveLocator handles getByRole pw spec', () => {
  const calls = [];
  const page = {
    getByRole(role, opts) {
      calls.push({ role, opts });
      return { first: () => ({}) };
    },
  };
  const loc = resolveLocator(page, { pw: 'getByRole', role: 'button', name: '^Post$' });
  assert.ok(loc);
  assert.equal(calls[0].role, 'button');
  assert.ok(calls[0].opts.name instanceof RegExp);
});

test('resolveLocator handles locator pw spec with filter', () => {
  const calls = [];
  const page = {
    locator(selector) {
      calls.push({ selector });
      return {
        filter(opts) {
          calls.push({ filter: opts });
          return { first: () => ({}) };
        },
      };
    },
  };
  const loc = resolveLocator(page, {
    pw: 'locator',
    selector: '[role=dialog]',
    filter: { hasText: 'Create a post' },
  });
  assert.ok(loc);
  assert.equal(calls[0].selector, '[role=dialog]');
  assert.ok(calls[1].filter.hasText instanceof RegExp);
});
