import test from 'node:test';
import assert from 'node:assert/strict';
import { getSuggestedPreSteps } from '../lib/flow-pre-steps.js';

test('getSuggestedPreSteps returns facebook sub post pre_steps', () => {
  const steps = getSuggestedPreSteps('facebook', 'post', 'sub');
  assert.deepEqual(steps, ['abort_if_unavailable']);
});

test('getSuggestedPreSteps returns facebook root post pre_steps', () => {
  const steps = getSuggestedPreSteps('facebook', 'post', 'root');
  assert.deepEqual(steps, ['ensure_personal_profile', 'abort_if_unavailable']);
});

test('getSuggestedPreSteps returns linkedin post pre_steps by account kind', () => {
  assert.deepEqual(getSuggestedPreSteps('linkedin', 'post', 'root'), ['assert_session']);
  assert.deepEqual(getSuggestedPreSteps('linkedin', 'post', 'sub'), ['assert_session']);
});

test('getSuggestedPreSteps returns empty for unknown flow', () => {
  assert.deepEqual(getSuggestedPreSteps('twitter', 'post'), []);
});
