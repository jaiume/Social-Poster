import test from 'node:test';
import assert from 'node:assert/strict';
import {
  bootstrapUrl,
  personalContextUrl,
  normalizeSubPageLocator,
  facebookBootstrapFromLocator,
  linkedinBootstrapFromLocator,
} from '../lib/session-account-urls.js';

test('bootstrapUrl for facebook root and numeric sub', () => {
  assert.equal(bootstrapUrl('facebook', 'root'), 'https://www.facebook.com/');
  assert.equal(
    bootstrapUrl('facebook', 'sub', '12345'),
    'https://www.facebook.com/profile.php?id=12345'
  );
});

test('bootstrapUrl for facebook vanity sub', () => {
  assert.equal(
    bootstrapUrl('facebook', 'sub', 'WiFiVentures'),
    'https://www.facebook.com/WiFiVentures'
  );
  assert.equal(
    facebookBootstrapFromLocator('WiFiVentures'),
    'https://www.facebook.com/WiFiVentures'
  );
});

test('normalizeSubPageLocator accepts id, username, and URLs', () => {
  assert.equal(
    normalizeSubPageLocator('facebook', 'https://www.facebook.com/profile.php?id=61565395796965'),
    '61565395796965'
  );
  assert.equal(
    normalizeSubPageLocator('facebook', 'https://www.facebook.com/WiFiVentures'),
    'WiFiVentures'
  );
  assert.equal(normalizeSubPageLocator('facebook', 'WiFiVentures'), 'WiFiVentures');
});

test('normalizeSubPageLocator rejects facebook groups URL', () => {
  assert.throws(
    () => normalizeSubPageLocator('facebook', 'https://www.facebook.com/groups/foo'),
    /Facebook page/
  );
});

test('bootstrapUrl for linkedin root, company, and showcase subs', () => {
  assert.equal(bootstrapUrl('linkedin', 'root'), 'https://www.linkedin.com/feed/');
  assert.equal(
    bootstrapUrl('linkedin', 'sub', 'company/20107831'),
    'https://www.linkedin.com/company/20107831/admin/page-posts/published'
  );
  assert.equal(
    bootstrapUrl('linkedin', 'sub', 'showcase/113183993'),
    'https://www.linkedin.com/showcase/113183993/admin/page-posts/published'
  );
  assert.equal(
    linkedinBootstrapFromLocator('acme'),
    'https://www.linkedin.com/showcase/acme/admin/page-posts/published'
  );
});

test('normalizeSubPageLocator parses linkedin company and showcase admin URLs', () => {
  assert.equal(
    normalizeSubPageLocator('linkedin', 'https://www.linkedin.com/company/20107831/admin/page-posts/published/'),
    'company/20107831'
  );
  assert.equal(
    normalizeSubPageLocator('linkedin', 'https://www.linkedin.com/showcase/113183993/admin/page-posts/published/'),
    'showcase/113183993'
  );
  assert.equal(normalizeSubPageLocator('linkedin', 'company/20107831'), 'company/20107831');
  assert.equal(normalizeSubPageLocator('linkedin', 'showcase:113183993'), 'showcase/113183993');
});

test('normalizeSubPageLocator rejects bare linkedin numeric id', () => {
  assert.throws(
    () => normalizeSubPageLocator('linkedin', '20107831'),
    /ambiguous/
  );
});

test('personalContextUrl uses personal feed', () => {
  assert.equal(personalContextUrl('facebook'), 'https://www.facebook.com/');
  assert.equal(personalContextUrl('linkedin'), 'https://www.linkedin.com/feed/');
});
