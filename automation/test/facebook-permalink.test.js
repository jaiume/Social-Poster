import test from 'node:test';
import assert from 'node:assert/strict';
import {
  canonicalizePostHref,
  pageKeyFromUrl,
  isDirectFacebookPostUrl,
  facebookUrlsMatch,
  facebookRepostSuccessUrl,
} from '../lib/facebook.js';

const pageUrl = 'https://www.facebook.com/profile.php?id=61565395796965';

test('pageKeyFromUrl extracts numeric page id', () => {
  assert.equal(pageKeyFromUrl(pageUrl), '61565395796965');
  assert.equal(pageKeyFromUrl('https://www.facebook.com/WiFiVentures'), 'WiFiVentures');
});

test('canonicalizePostHref accepts permalink.php with story_fbid', () => {
  const href = 'https://www.facebook.com/permalink.php?story_fbid=pfbid02abc&id=61565395796965';
  const resolved = canonicalizePostHref(pageUrl, href);
  assert.equal(resolved, href);
  assert.equal(isDirectFacebookPostUrl(resolved), true);
});

test('canonicalizePostHref builds posts path from pfbid fragment', () => {
  const href = '/61565395796965/posts/pfbid02abc?__cft__=1';
  const resolved = canonicalizePostHref(pageUrl, href);
  assert.equal(resolved, 'https://www.facebook.com/61565395796965/posts/pfbid02abc');
});

test('canonicalizePostHref decodes l.facebook.com redirect', () => {
  const wrapped = 'https://l.facebook.com/l.php?u=' + encodeURIComponent(
    'https://www.facebook.com/permalink.php?story_fbid=pfbid02xyz&id=61565395796965'
  );
  const resolved = canonicalizePostHref(pageUrl, wrapped);
  assert.match(resolved || '', /story_fbid=pfbid02xyz/);
  assert.equal(isDirectFacebookPostUrl(resolved), true);
});

test('canonicalizePostHref rejects plain page profile links', () => {
  assert.equal(
    canonicalizePostHref(pageUrl, 'https://www.facebook.com/profile.php?id=61565395796965'),
    null
  );
});

test('canonicalizePostHref accepts share/p URLs', () => {
  const href = 'https://www.facebook.com/share/p/abc123/';
  const resolved = canonicalizePostHref(pageUrl, href);
  assert.equal(resolved, href);
  assert.equal(isDirectFacebookPostUrl(resolved), true);
});

test('canonicalizePostHref builds posts path from insights-style href', () => {
  const href = '/professional_dashboard/insights/posts/?post_id=pfbid02abc123';
  const resolved = canonicalizePostHref(pageUrl, href);
  assert.equal(resolved, 'https://www.facebook.com/61565395796965/posts/pfbid02abc123');
});

test('canonicalizePostHref builds permalink from boost post admin link', () => {
  const href = '/ad_center/create/boostpost/?ad_account_id=18591187&page_id=402147570427332&target_id=1413618307476445';
  const resolved = canonicalizePostHref(pageUrl, href);
  assert.equal(
    resolved,
    'https://www.facebook.com/permalink.php?story_fbid=1413618307476445&id=402147570427332'
  );
});

test('isDirectFacebookPostUrl rejects insights dashboard links', () => {
  assert.equal(
    isDirectFacebookPostUrl('https://www.facebook.com/professional_dashboard/insights/posts/?post_id=pfbid02abc'),
    false
  );
});

test('isDirectFacebookPostUrl rejects photo.php viewer links', () => {
  assert.equal(
    isDirectFacebookPostUrl('https://www.facebook.com/photo.php?fbid=123&id=61565395796965'),
    false
  );
});

test('facebookUrlsMatch compares origin path and query', () => {
  const a = 'https://www.facebook.com/permalink.php?story_fbid=pfbid02&id=123';
  const b = 'https://www.facebook.com/permalink.php?story_fbid=pfbid02&id=123';
  assert.equal(facebookUrlsMatch(a, b), true);
  assert.equal(
    facebookUrlsMatch(a, 'https://www.facebook.com/permalink.php?story_fbid=pfbid02&id=456'),
    false
  );
});

test('facebookRepostSuccessUrl prefers personalContextUrl', () => {
  assert.equal(
    facebookRepostSuccessUrl({ personalContextUrl: 'https://www.facebook.com/' }),
    'https://www.facebook.com/'
  );
  assert.equal(facebookRepostSuccessUrl({}), 'https://www.facebook.com/');
});
