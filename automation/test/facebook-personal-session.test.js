import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { ensureFacebookPersonalSession } from '../lib/facebook.js';

async function launchBrowserOrSkip(t) {
  try {
    return await chromium.launch({ headless: true });
  } catch (e) {
    t.skip(`Playwright browser not available: ${e.message}`);
    return null;
  }
}

test('ensureFacebookPersonalSession resolves immediately when not in a managed-page identity', async (t) => {
  const browser = await launchBrowserOrSkip(t);
  if (!browser) return;

  const page = await browser.newPage();
  await page.route('https://www.facebook.com/**', (route) => route.fulfill({
    status: 200,
    contentType: 'text/html',
    body: '<div><a href="#">Home</a></div>',
  }));
  await page.goto('https://www.facebook.com/');

  const result = await ensureFacebookPersonalSession(page, 'https://www.facebook.com/', /EntryZen/i);
  assert.equal(result, true);

  await browser.close();
});

test('ensureFacebookPersonalSession throws when it cannot switch out of the managed-page identity', async (t) => {
  const browser = await launchBrowserOrSkip(t);
  if (!browser) return;

  const page = await browser.newPage();
  // "Professional dashboard" is a stable signal that the current identity is
  // scoped to a managed Page (Meta Business Suite navigation), and no Feeds/
  // Home/account-switcher controls are present, so every attempt to switch
  // back to the personal profile fails, leaving this signal visible.
  await page.route('https://www.facebook.com/**', (route) => route.fulfill({
    status: 200,
    contentType: 'text/html',
    body: '<div><a href="#">Professional dashboard</a></div>',
  }));
  await page.goto('https://www.facebook.com/');

  await assert.rejects(
    () => ensureFacebookPersonalSession(page, 'https://www.facebook.com/', /EntryZen/i),
    /Could not switch Facebook out of the managed-page identity/i
  );

  await browser.close();
});

test('ensureFacebookPersonalSession clicks the name-shaped switcher entry (button role) over the page-brand entry', async (t) => {
  const browser = await launchBrowserOrSkip(t);
  if (!browser) return;

  const page = await browser.newPage();
  // Mirrors the real Facebook UI observed in production: the account
  // switcher renders entries as role="button" (not role="menuitem"), listing
  // both the personal profile ("Jamie McLachlan") and other pages the
  // account administers ("WifiVentures.co.tt") alongside the page you're
  // trying to leave ("EntryZen", the current identity, and thus not itself
  // listed as a switch target).
  await page.route('https://www.facebook.com/**', (route) => route.fulfill({
    status: 200,
    contentType: 'text/html',
    body: `
      <div id="page-chrome">
        <a href="#">Professional dashboard</a>
        <button id="your-profile-trigger">Your profile</button>
      </div>
      <div id="switcher" style="display:none">
        <button id="jamie">Jamie McLachlan</button>
        <button id="wifiventures">WifiVentures.co.tt</button>
        <button>See all profiles</button>
      </div>
      <script>
        document.getElementById('your-profile-trigger').addEventListener('click', () => {
          document.getElementById('switcher').style.display = 'block';
        });
        document.getElementById('jamie').addEventListener('click', () => {
          document.getElementById('page-chrome').remove();
          document.getElementById('switcher').remove();
        });
        document.getElementById('wifiventures').addEventListener('click', () => {
          // Switching to a different managed page should NOT clear the
          // page-admin signal; only clicking "Jamie McLachlan" should.
        });
      </script>
    `,
  }));
  await page.goto('https://www.facebook.com/');

  const result = await ensureFacebookPersonalSession(page, 'https://www.facebook.com/', /EntryZen/i);
  assert.equal(result, true);

  await browser.close();
});

test('ensureFacebookPersonalSession succeeds once the Home link switches out of the managed-page identity', async (t) => {
  const browser = await launchBrowserOrSkip(t);
  if (!browser) return;

  const page = await browser.newPage();
  // Every real navigation (via the route) still serves the "stuck in page
  // mode" markup; clicking "Home" fixes it in-page (no navigation), which is
  // what proves the fix worked rather than a lucky re-fetch.
  await page.route('https://www.facebook.com/**', (route) => route.fulfill({
    status: 200,
    contentType: 'text/html',
    body: `
      <div>
        <a href="#">Professional dashboard</a>
        <a href="#" id="home-link">Home</a>
      </div>
      <script>
        document.getElementById('home-link').addEventListener('click', (e) => {
          e.preventDefault();
          document.body.innerHTML = '<a href="#">Home</a>';
        });
      </script>
    `,
  }));
  await page.goto('https://www.facebook.com/');

  const result = await ensureFacebookPersonalSession(page, 'https://www.facebook.com/', /EntryZen/i);
  assert.equal(result, true);

  await browser.close();
});
