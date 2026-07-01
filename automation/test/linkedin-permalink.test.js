import test from 'node:test';
import assert from 'node:assert/strict';
import {
  canonicalizeLinkedInPostHref,
  isDirectLinkedInPostUrl,
  linkedInUrlsMatch,
  linkedInRepostBootstrapUrl,
  linkedInRepostSuccessUrl,
  resolveLinkedInPrimaryPostUrl,
} from '../lib/linkedin.js';

test('canonicalizeLinkedInPostHref resolves relative feed update path', () => {
  const href = '/feed/update/urn:li:activity:7123456789012345678/';
  const resolved = canonicalizeLinkedInPostHref(href);
  assert.equal(resolved, 'https://www.linkedin.com/feed/update/urn:li:activity:7123456789012345678/');
  assert.equal(isDirectLinkedInPostUrl(resolved), true);
});

test('canonicalizeLinkedInPostHref accepts absolute posts URL', () => {
  const href = 'https://www.linkedin.com/posts/wifiventures_wifi-update-activity-7123456789012345678-abcd';
  const resolved = canonicalizeLinkedInPostHref(href);
  assert.equal(resolved, href);
});

test('canonicalizeLinkedInPostHref rejects company admin URLs', () => {
  assert.equal(
    canonicalizeLinkedInPostHref('https://www.linkedin.com/company/wifiventures/admin/'),
    null
  );
});

test('linkedInUrlsMatch ignores trailing slash differences', () => {
  const a = 'https://www.linkedin.com/feed/update/urn:li:activity:123/';
  const b = 'https://www.linkedin.com/feed/update/urn:li:activity:123';
  assert.equal(linkedInUrlsMatch(a, b), true);
});

test('linkedInUrlsMatch compares actorCompanyId when both present', () => {
  const a = 'https://www.linkedin.com/feed/update/urn:li:activity:123?actorCompanyId=wifi';
  const b = 'https://www.linkedin.com/feed/update/urn:li:activity:123?actorCompanyId=other';
  assert.equal(linkedInUrlsMatch(a, b), false);
});

test('linkedInUrlsMatch matches same path when only one URL has actorCompanyId', () => {
  const a = 'https://www.linkedin.com/feed/update/urn:li:activity:123';
  const b = 'https://www.linkedin.com/feed/update/urn:li:activity:123?actorCompanyId=wifiventures';
  assert.equal(linkedInUrlsMatch(a, b), true);
});

test('resolveLinkedInPrimaryPostUrl canonicalizes relative activity URLs', () => {
  const resolved = resolveLinkedInPrimaryPostUrl('/feed/update/urn:li:activity:123/');
  assert.equal(resolved, 'https://www.linkedin.com/feed/update/urn:li:activity:123/');
});

test('linkedInRepostSuccessUrl prefers personalContextUrl', () => {
  assert.equal(
    linkedInRepostSuccessUrl({ personalContextUrl: 'https://www.linkedin.com/feed/' }),
    'https://www.linkedin.com/feed/'
  );
  assert.equal(linkedInRepostSuccessUrl({}), 'https://www.linkedin.com/feed/');
});

test('linkedInRepostBootstrapUrl adds actorCompanyId from showcase page', () => {
  const url = linkedInRepostBootstrapUrl(
    'https://www.linkedin.com/feed/update/urn:li:activity:123',
    'https://www.linkedin.com/showcase/wifiventures/',
    'https://www.linkedin.com/feed/'
  );
  assert.equal(
    url,
    'https://www.linkedin.com/feed/update/urn:li:activity:123?actorCompanyId=wifiventures'
  );
});

test('linkedInRepostPersonalBootstrapUrl strips company actor from post URL', async () => {
  const { linkedInRepostPersonalBootstrapUrl, isPersonalLinkedInPostView } = await import('../lib/linkedin.js');
  const url = linkedInRepostPersonalBootstrapUrl(
    'https://www.linkedin.com/feed/update/urn:li:activity:123?actorCompanyId=wifiventures',
    'https://www.linkedin.com/feed/'
  );
  assert.equal(url, 'https://www.linkedin.com/feed/update/urn:li:activity:123');
  assert.equal(isPersonalLinkedInPostView(url), true);
  assert.equal(
    isPersonalLinkedInPostView('https://www.linkedin.com/feed/update/urn:li:activity:123?actorCompanyId=1'),
    false
  );
});

test('resolveLinkedInPrimaryPostPermalink returns null when feed has no matching post', async () => {
  const { resolveLinkedInPrimaryPostPermalink } = await import('../lib/linkedin.js');

  const empty = {
    count: async () => 0,
    nth: () => ({ getAttribute: async () => null, locator: () => ({ evaluateAll: async () => [] }) }),
    filter: () => empty,
    first: () => ({ getAttribute: async () => null }),
  };
  const page = {
    url: () => 'https://www.linkedin.com/company/example/posts/',
    goto: async () => {},
    waitForLoadState: async () => {},
    waitForTimeout: async () => {},
    locator: () => empty,
  };

  const result = await resolveLinkedInPrimaryPostPermalink(
    page,
    'https://www.linkedin.com/company/example/admin/',
    { textHint: 'hello world', accountKind: 'sub' }
  );
  assert.equal(result, null);
});

test('resolveLinkedInPrimaryPostPermalink captures data-urn from feed update', async () => {
  const { resolveLinkedInPrimaryPostPermalink } = await import('../lib/linkedin.js');

  const update = {
    getAttribute: async (name) => name === 'data-urn' ? 'urn:li:activity:987654321' : null,
    innerText: async () => 'hello world this is a post',
    locator: () => ({ evaluateAll: async () => [] }),
  };
  const empty = {
    count: async () => 0,
    nth: () => update,
    filter: () => empty,
    first: () => ({ getAttribute: async () => null }),
  };
  const withUpdates = {
    count: async () => 1,
    nth: () => update,
    filter: () => withUpdates,
    first: () => ({ getAttribute: async () => null }),
  };
  let call = 0;
  const page = {
    url: () => 'https://www.linkedin.com/company/example/posts/',
    goto: async () => {},
    waitForLoadState: async () => {},
    waitForTimeout: async () => {},
    locator: () => {
      call += 1;
      // First locator ([data-urn*="urn:li:..."]) returns matches; others return empty.
      return call === 1 ? withUpdates : empty;
    },
  };

  const result = await resolveLinkedInPrimaryPostPermalink(
    page,
    'https://www.linkedin.com/company/example/admin/',
    { textHint: 'hello world', accountKind: 'sub' }
  );
  assert.equal(result, 'https://www.linkedin.com/feed/update/urn:li:activity:987654321/');
});
