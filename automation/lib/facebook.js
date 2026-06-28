import { TYPE_DELAY_MS } from './timing.js';
import { humanPause } from './session.js';
import fs from 'fs';
import { attachImage } from './media.js';

export async function screenshot(page, input, prefix) {
  const screenshotPath = `${input.screenshotDir}/${prefix}-${Date.now()}.png`;
  await page.screenshot({ path: screenshotPath, fullPage: false });
  return screenshotPath;
}

export async function ensureFacebookPageLoaded(page, profileHint = null) {
  const unavailable = page.getByText(/this page isn't available|page isn't available|content isn't available/i);
  if (await unavailable.first().isVisible({ timeout: 2000 }).catch(() => false)) {
    throw new Error('Facebook page is not available at this URL. Verify the page URL and that the session can manage this page.');
  }

  const loginEmail = page.locator('input[name="email"], #email');
  if (await loginEmail.isVisible({ timeout: 1500 }).catch(() => false)) {
    throw new Error('Facebook session expired. Re-capture the Facebook session.');
  }

  const checkpoint = page.getByText(/confirm your identity|security check|enter security code|checkpoint loop|not a robot/i);
  if (await checkpoint.first().isVisible({ timeout: 1000 }).catch(() => false)) {
    throw new Error(
      'Facebook served a bot checkpoint to the automated browser (your normal browser may look fine). Try Settings → disable Headless browser, re-capture the session, then retry.'
    );
  }

  const recaptcha = page.locator('iframe[src*="recaptcha"], [id*="captcha"], [class*="recaptcha"]');
  if (await recaptcha.first().isVisible({ timeout: 800 }).catch(() => false)) {
    throw new Error(
      'Facebook reCAPTCHA appeared in the automated browser session. Re-capture the session from a warmed-up Feeds view, then retry.'
    );
  }

  const otp = page.locator(
    'input[autocomplete="one-time-code"], input[name="approvals_code"], input#approvals_code'
  );
  if (await otp.first().isVisible({ timeout: 800 }).catch(() => false)) {
    throw new Error('Facebook two-factor authentication required. Re-capture the session and complete the authenticator step.');
  }

  const verifyHeading = page.getByText(/authentication code|two-factor|2-factor|approvals code|enter login code|authenticator/i);
  if (await verifyHeading.first().isVisible({ timeout: 800 }).catch(() => false)) {
    throw new Error('Facebook two-factor authentication required. Re-capture the session and complete the authenticator step.');
  }

  await activateFacebookProfileSession(page, profileHint);
}

function profileFirstNameFromHint(profileHint) {
  if (!profileHint) {
    return null;
  }
  const slug = String(profileHint).match(/facebook\.com\/([^/?#]+)/i)?.[1];
  if (!slug || slug === 'profile.php') {
    return null;
  }
  return slug.split(/[._-]/)[0] || null;
}

async function activateFacebookProfileSession(page, profileHint = null) {
  const body = ((await page.locator('body').innerText().catch(() => '')) || '').toLowerCase();
  const onProfilePicker = /use another profile|create new account|explore the things you love/i.test(body);
  if (!onProfilePicker) {
    return false;
  }

  const firstName = profileFirstNameFromHint(profileHint);
  if (firstName) {
    const namedContinue = page.getByRole('button', { name: new RegExp(`continue.*${firstName}`, 'i') });
    if (await namedContinue.first().isVisible({ timeout: 2000 }).catch(() => false)) {
      const label = await namedContinue.first().getAttribute('aria-label');
      console.error(`[facebook] Opening saved profile from picker via ${label || namedContinue}`);
      await namedContinue.first().click({ force: true });
      if (await page.getByText(/what'?s on your mind/i).first().isVisible({ timeout: 5000 }).catch(() => false)) {
        return true;
      }
    }
  }

  const labeledContinue = page.locator('[aria-label^="Continue " i], [aria-label*="Continue " i][role="button"]');
  const labeledCount = await labeledContinue.count();
  for (let i = 0; i < labeledCount; i++) {
    const button = labeledContinue.nth(i);
    const label = ((await button.getAttribute('aria-label').catch(() => '')) || '').trim();
    if (!label || /use another profile|create new account/i.test(label)) {
      continue;
    }
    console.error(`[facebook] Opening saved profile from picker via ${label}`);
    await button.click({ force: true });
    if (await page.getByText(/what'?s on your mind/i).first().isVisible({ timeout: 5000 }).catch(() => false)) {
      return true;
    }
  }

  const continueButtons = [
    page.getByRole('button', { name: /^continue$/i }),
    page.locator('a, button, [role="button"]').filter({ hasText: /^continue$/i }),
  ];
  for (const locator of continueButtons) {
    const button = locator.first();
    if (!(await button.isVisible({ timeout: 1500 }).catch(() => false))) {
      continue;
    }
    console.error('[facebook] Activating saved profile session via Continue');
    await button.click({ force: true });
    if (await page.getByText(/what'?s on your mind/i).first().isVisible({ timeout: 5000 }).catch(() => false)) {
      return true;
    }
  }

  return false;
}

export async function navigateToFacebookPage(page, pageUrl) {
  await page.goto(pageUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await ensureFacebookPageLoaded(page);

  const pageReady = [
    page.getByText(/what'?s on your mind/i),
    page.getByText(/manage page/i),
    page.getByRole('button', { name: /switch now/i }),
    page.getByText(/switch into .+ page to start managing/i),
  ];
  for (const locator of pageReady) {
    if (await locator.first().isVisible({ timeout: 3000 }).catch(() => false)) {
      return;
    }
  }

  throw new Error('Page loaded but composer controls were not visible.');
}

export function facebookUrlsMatch(current, target) {
  try {
    const a = new URL(current, 'https://www.facebook.com');
    const b = new URL(target, 'https://www.facebook.com');
    return a.origin === b.origin && a.pathname === b.pathname && a.search === b.search;
  } catch {
    return false;
  }
}

/**
 * Personal home URL stored as external_post_url after a successful repost.
 * @param {{ personalContextUrl?: string, personal_context_url?: string, targetPageUrl?: string }} input
 * @returns {string}
 */
export function facebookRepostSuccessUrl(input = {}) {
  return input.personalContextUrl
    || input.personal_context_url
    || input.targetPageUrl
    || 'https://www.facebook.com/';
}

export async function dismissCoverPhotoEditor(page) {
  const cancel = page.getByRole('button', { name: /^cancel$/i });
  if (await cancel.isVisible({ timeout: 1500 }).catch(() => false)) {
    console.error('[facebook] Dismissing cover photo editor');
    await cancel.click();
  }
}

async function needsPageAdminSwitch(page) {
  const switchNow = page.getByRole('button', { name: /switch now/i });
  if (await switchNow.isVisible({ timeout: 800 }).catch(() => false)) {
    return true;
  }

  const switchBanner = page.getByText(/switch into .+ page to start managing/i);
  if (await switchBanner.isVisible({ timeout: 800 }).catch(() => false)) {
    return true;
  }

  const switchProfilesDialog = page.locator('[role="dialog"]').filter({
    hasText: /switch profiles|switch to .+ for more features/i,
  });
  return await switchProfilesDialog.first().isVisible({ timeout: 800 }).catch(() => false);
}

async function dismissPostViewer(page) {
  const postViewerSignals = [
    page.getByRole('button', { name: /^boost post$/i }),
    page.locator('[aria-label*="Zoom in" i], [aria-label*="Zoom out" i], [aria-label*="Full screen" i]'),
    page.locator('[role="dialog"]').filter({ hasText: /boost post/i }),
  ];

  let inPostViewer = false;
  for (const signal of postViewerSignals) {
    if (await signal.first().isVisible({ timeout: 600 }).catch(() => false)) {
      inPostViewer = true;
      break;
    }
  }

  if (!inPostViewer) {
    return false;
  }

  console.error('[facebook] Dismissing post viewer overlay');
  const closeButtons = [
    page.getByRole('button', { name: /^close$/i }),
    page.locator('[aria-label*="Close" i][role="button"]'),
    page.locator('[aria-label*="Back to previous page" i]'),
  ];
  for (const locator of closeButtons) {
    const button = locator.first();
    if (await button.isVisible({ timeout: 1000 }).catch(() => false)) {
      await button.click();
      await page.waitForTimeout(500).catch(() => {});
      return true;
    }
  }

  await page.keyboard.press('Escape');
  await page.waitForTimeout(500).catch(() => {});
  return true;
}

async function waitForPageComposerTrigger(page) {
  await page.getByText(/what'?s on your mind/i).first()
    .waitFor({ state: 'visible', timeout: 20000 })
    .catch(() => {});
}

async function confirmSwitchProfilesDialog(page) {
  const switchProfilesDialog = page.locator('[role="dialog"]').filter({
    hasText: /switch profiles|switch to .+ for more features/i,
  });
  if (!(await switchProfilesDialog.first().isVisible({ timeout: 2000 }).catch(() => false))) {
    return false;
  }

  const switchProfile = switchProfilesDialog.first().getByRole('button', { name: /^switch$/i });
  if (!(await switchProfile.isVisible({ timeout: 2000 }).catch(() => false))) {
    return false;
  }

  console.error('[facebook] Clicking Switch in Switch profiles dialog');
  await switchProfile.click();
  await page.waitForTimeout(1000).catch(() => {});
  await waitForPageComposerTrigger(page);
  return true;
}

export async function ensurePageAdminMode(page) {
  await dismissPostViewer(page);
  await dismissCoverPhotoEditor(page);
  await page.waitForLoadState('domcontentloaded');

  if (await confirmSwitchProfilesDialog(page)) {
    return true;
  }

  const switchNow = page.getByRole('button', { name: /switch now/i });
  if (await switchNow.isVisible({ timeout: 3000 }).catch(() => false)) {
    console.error('[facebook] Clicking Switch Now to enter page admin mode');
    await switchNow.click();
    await waitForPageComposerTrigger(page);
    return true;
  }

  const switchBanner = page.getByText(/switch into .+ page to (?:start managing|take more actions)/i);
  if (await switchBanner.isVisible({ timeout: 1500 }).catch(() => false)) {
    const nearbySwitch = page.getByRole('button', { name: /^switch$/i }).first();
    if (await nearbySwitch.isVisible({ timeout: 1500 }).catch(() => false)) {
      console.error('[facebook] Clicking Switch from page management banner');
      await nearbySwitch.click();
      await page.waitForTimeout(500).catch(() => {});
      if (await confirmSwitchProfilesDialog(page)) {
        return true;
      }
      await waitForPageComposerTrigger(page);
      return true;
    }
  }

  return false;
}

function pageBrandPatternFromPageUrl(pageUrl) {
  const key = pageKeyFromUrl(pageUrl);
  if (!key) {
    return null;
  }
  return new RegExp(key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i');
}

async function openAccountProfileMenu(page) {
  const openers = [
    page.locator('[aria-label*="Account controls" i]'),
    page.locator('[aria-label*="Account menu" i]'),
    page.locator('[aria-label*="Profile" i][role="button"]'),
  ];
  for (const opener of openers) {
    const button = opener.first();
    if (await button.isVisible({ timeout: 1000 }).catch(() => false)) {
      console.error('[facebook] Opening account profile menu');
      await button.click();
      await page.waitForTimeout(400).catch(() => {});
      return true;
    }
  }
  return false;
}

async function switchToPageActorViaAccountMenu(page, pageUrl, pageBrandPattern = null) {
  const pattern = pageBrandPattern || pageBrandPatternFromPageUrl(pageUrl);
  if (!pattern) {
    return false;
  }

  if (!(await openAccountProfileMenu(page))) {
    return false;
  }

  const seeAllProfiles = page.getByRole('menuitem', { name: /see all profiles/i });
  if (await seeAllProfiles.isVisible({ timeout: 1500 }).catch(() => false)) {
    await seeAllProfiles.click();
    await page.waitForTimeout(500).catch(() => {});
  }

  const candidates = [
    page.getByRole('button', { name: pattern }),
    page.getByRole('menuitem', { name: pattern }),
    page.getByRole('link', { name: pattern }),
    page.locator('[role="button"], [role="menuitem"], [role="link"]').filter({ hasText: pattern }),
  ];
  for (const locator of candidates) {
    const el = locator.first();
    if (!(await el.isVisible({ timeout: 2000 }).catch(() => false))) {
      continue;
    }
    const label = ((await el.innerText().catch(() => '')) || '').trim();
    if (/your profile|use another profile|create new account/i.test(label)) {
      continue;
    }
    console.error(`[facebook] Switching to page profile: ${label.slice(0, 60)}`);
    await el.click();
    await page.waitForTimeout(1000).catch(() => {});
    if (!facebookUrlsMatch(page.url(), pageUrl)) {
      await page.goto(pageUrl, { waitUntil: 'domcontentloaded', timeout: 30000 }).catch(() => {});
      await page.waitForTimeout(1000).catch(() => {});
    }
    return true;
  }

  await page.keyboard.press('Escape').catch(() => {});
  return false;
}

async function scrollToPagePostsComposer(page) {
  await page.locator('[role="main"]').first().evaluate((el) => {
    el.scrollTo({ top: el.scrollHeight, behavior: 'instant' });
  }).catch(() => {});
  await page.waitForTimeout(800).catch(() => {});
}

async function switchProfessionalDashboardToTargetPage(page, pageBrandPattern) {
  if (!pageBrandPattern || !/professional_dashboard/i.test(page.url())) {
    return false;
  }

  const createPost = page.getByRole('button', { name: /^create a post$/i }).first();
  const statusMatch = page.locator('a, button, span, div').filter({ hasText: pageBrandPattern }).first();
  if (await statusMatch.isVisible({ timeout: 1000 }).catch(() => false)
    && await createPost.isVisible({ timeout: 1000 }).catch(() => false)) {
    return true;
  }

  const pageStatusSection = page.getByText(/^page status$/i).first();
  if (await pageStatusSection.isVisible({ timeout: 1500 }).catch(() => false)) {
    const activePage = pageStatusSection.locator('xpath=ancestor::*[1]').locator('a, button').first();
    if (await activePage.isVisible({ timeout: 1000 }).catch(() => false)) {
      const currentName = ((await activePage.innerText().catch(() => '')) || '').trim();
      if (!pageBrandPattern.test(currentName)) {
        console.error(`[facebook] Switching professional dashboard page from ${currentName.slice(0, 40)}`);
        await activePage.click();
        await page.waitForTimeout(500).catch(() => {});
        const target = page.getByRole('menuitem', { name: pageBrandPattern }).first();
        if (await target.isVisible({ timeout: 2000 }).catch(() => false)) {
          await target.click();
          await page.waitForTimeout(1500).catch(() => {});
          return true;
        }
        const targetButton = page.getByRole('button', { name: pageBrandPattern }).first();
        if (await targetButton.isVisible({ timeout: 2000 }).catch(() => false)) {
          await targetButton.click();
          await page.waitForTimeout(1500).catch(() => {});
          return true;
        }
      }
    }
  }

  const switchProfiles = [
    page.getByRole('link', { name: /switch profiles/i }),
    page.getByRole('button', { name: /switch profiles/i }),
    page.getByText(/switch profiles/i),
  ];
  for (const locator of switchProfiles) {
    const el = locator.first();
    if (!(await el.isVisible({ timeout: 1500 }).catch(() => false))) {
      continue;
    }
    console.error('[facebook] Opening profile switcher from professional dashboard');
    await el.click();
    await page.waitForTimeout(500).catch(() => {});
    const target = page.getByRole('button', { name: pageBrandPattern }).first();
    if (await target.isVisible({ timeout: 2500 }).catch(() => false)) {
      await target.click();
      await page.waitForTimeout(1500).catch(() => {});
      return true;
    }
    break;
  }

  if (await openAccountProfileMenu(page)) {
    const seeAllProfiles = page.getByRole('menuitem', { name: /see all profiles/i });
    if (await seeAllProfiles.isVisible({ timeout: 1500 }).catch(() => false)) {
      await seeAllProfiles.click();
      await page.waitForTimeout(500).catch(() => {});
    }
    const target = page.getByRole('button', { name: pageBrandPattern }).first();
    if (await target.isVisible({ timeout: 2000 }).catch(() => false)) {
      console.error('[facebook] Switching professional dashboard to target page profile');
      await target.click();
      await page.waitForTimeout(1500).catch(() => {});
      return true;
    }
    await page.keyboard.press('Escape').catch(() => {});
  }

  return false;
}

async function openProfessionalDashboardComposer(page) {
  if (!/professional_dashboard/i.test(page.url())) {
    return false;
  }

  const quickActions = [
    page.locator('aside, nav').getByRole('button', { name: /^post$/i }).first(),
    page.getByRole('button', { name: /^create a post$/i }).first(),
    page.getByRole('menuitem', { name: /^post$/i }).first(),
  ];
  for (const action of quickActions) {
    if (!(await action.isVisible({ timeout: 2000 }).catch(() => false))) {
      continue;
    }
    console.error('[facebook] Opening composer from professional dashboard');
    await action.click();
    await page.waitForTimeout(1200).catch(() => {});
    if (await focusComposerDialog(page)) {
      return true;
    }
    const postMenuItem = page.getByRole('menuitem', { name: /^post$/i }).first();
    if (await postMenuItem.isVisible({ timeout: 1500 }).catch(() => false)) {
      await postMenuItem.click();
      await page.waitForTimeout(1200).catch(() => {});
      return Boolean(await focusComposerDialog(page));
    }
  }
  return false;
}

async function hasPageComposerTrigger(page) {
  const triggers = [
    page.getByText(/what'?s on your mind/i),
    page.locator('[aria-label*="Create a post" i]'),
    page.locator('[aria-label*="What\'s on your mind" i]'),
    page.getByRole('button', { name: /create a post/i }),
    page.locator('[role="button"]').filter({ hasText: /what'?s on your mind/i }),
  ];
  for (const locator of triggers) {
    if (await locator.first().isVisible({ timeout: 800 }).catch(() => false)) {
      return true;
    }
  }
  return false;
}

/**
 * Open the page Posts surface when admin sidebar is visible but the composer trigger is not.
 * @param {import('playwright').Page} page
 * @param {string} pageUrl
 * @returns {Promise<boolean>}
 */
export async function openPagePostsSurface(page, pageUrl) {
  await page.locator('[role="main"]').first().evaluate((el) => {
    el.scrollTo({ top: 0, behavior: 'instant' });
  }).catch(() => {});

  const postsNav = [
    page.getByRole('tab', { name: /^posts$/i }),
    page.getByRole('link', { name: /^posts$/i }),
    page.locator('a[href*="sk=posts"], a[href*="/posts"]').filter({ hasText: /^posts$/i }),
  ];
  for (const locator of postsNav) {
    const el = locator.first();
    if (await el.isVisible({ timeout: 2000 }).catch(() => false)) {
      console.error('[facebook] Opening Page Posts tab');
      await el.scrollIntoViewIfNeeded().catch(() => {});
      await el.click();
      await page.waitForLoadState('domcontentloaded', { timeout: 10000 }).catch(() => {});
      await page.waitForTimeout(800).catch(() => {});
      return true;
    }
  }

  try {
    const url = new URL(pageUrl, 'https://www.facebook.com');
    if (!/sk=posts/i.test(url.toString())) {
      url.searchParams.set('sk', 'posts');
      console.error(`[facebook] Navigating to page posts URL ${url.toString()}`);
      await page.goto(url.toString(), { waitUntil: 'domcontentloaded', timeout: 30000 });
      await page.waitForTimeout(800).catch(() => {});
      return true;
    }
  } catch {
    // ignore
  }

  return false;
}

async function openPageProfessionalDashboard(page) {
  const profDash = page.getByRole('link', { name: /professional dashboard/i }).first();
  const onPageSurface = await profDash.isVisible({ timeout: 2000 }).catch(() => false);

  console.error('[facebook] Opening Professional dashboard for page composer');
  if (onPageSurface) {
    await profDash.scrollIntoViewIfNeeded().catch(() => {});
    const clicked = await profDash.click({ timeout: 5000 }).then(() => true).catch(() => false);
    if (!clicked) {
      await page.goto('https://www.facebook.com/professional_dashboard/', {
        waitUntil: 'domcontentloaded',
        timeout: 30000,
      }).catch(() => {});
    }
  } else {
    await page.goto('https://www.facebook.com/professional_dashboard/', {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
    }).catch(() => {});
  }

  await page.waitForLoadState('domcontentloaded', { timeout: 30000 }).catch(() => {});
  await page.waitForTimeout(1500).catch(() => {});
  return /professional_dashboard/i.test(page.url());
}

export async function ensurePageReadyForComposer(page, pageUrl, pageBrandPattern = null) {
  const brand = pageBrandPattern || pageBrandPatternFromPageUrl(pageUrl);

  if (await hasPageComposerTrigger(page)) {
    return;
  }

  await switchToPageActorViaAccountMenu(page, pageUrl, brand);
  if (await hasPageComposerTrigger(page)) {
    return;
  }

  const managePageSidebar = page.getByText(/^manage page$/i);
  if (await managePageSidebar.first().isVisible({ timeout: 1000 }).catch(() => false)) {
    console.error('[facebook] Page admin sidebar visible without composer; opening composer surface');
    if (await openPageProfessionalDashboard(page)) {
      await switchProfessionalDashboardToTargetPage(page, brand);
      return;
    }
    await openPagePostsSurface(page, pageUrl);
    await scrollToPagePostsComposer(page);
    return;
  }

  if (/professional_dashboard/i.test(page.url())) {
    await switchProfessionalDashboardToTargetPage(page, brand);
  }
}

export async function isPageAdminMode(page) {
  if (await needsPageAdminSwitch(page)) {
    return false;
  }

  const adminSignals = [
    page.getByRole('link', { name: /^edit profile$/i }),
    page.getByRole('button', { name: /edit cover photo/i }),
    page.getByRole('tab', { name: /^posts$/i }).and(page.getByRole('button', { name: /what'?s on your mind/i })),
  ];

  for (const signal of adminSignals) {
    if (await signal.first().isVisible({ timeout: 800 }).catch(() => false)) {
      return true;
    }
  }

  const composer = page.getByRole('button', { name: /what'?s on your mind/i }).first();
  if (await composer.isVisible({ timeout: 800 }).catch(() => false)) {
    const composerLabel = ((await composer.getAttribute('aria-label').catch(() => '')) || '').toLowerCase();
    if (composerLabel.includes('page') && !composerLabel.includes('your profile')) {
      return true;
    }
  }

  return false;
}

/** True when the home feed is scoped to a managed page (not the personal profile). */
async function isActingAsPageOnFeed(page) {
  const pageFeedSignals = [
    page.getByRole('link', { name: /professional dashboard/i }),
    page.getByRole('link', { name: /ads manager/i }),
    page.getByRole('link', { name: /meta business suite/i }),
    page.getByRole('link', { name: /ad center/i }),
  ];

  for (const signal of pageFeedSignals) {
    if (await signal.first().isVisible({ timeout: 800 }).catch(() => false)) {
      return true;
    }
  }

  return false;
}

async function clickPersonalProfileInSwitcher(page, personalProfileUrl = null) {
  const profileHint = profileHintPatternFromUrl(personalProfileUrl);
  const menuButtons = [
    page.locator('[aria-label*="Account controls" i]'),
    page.locator('[aria-label*="Account menu" i]'),
    page.getByRole('button', { name: /^account$/i }),
  ];

  for (const locator of menuButtons) {
    const button = locator.first();
    if (await button.isVisible({ timeout: 1000 }).catch(() => false)) {
      console.error('[facebook] Opening account menu to switch to personal profile');
      await button.click();
      break;
    }
  }

  const yourProfileItem = page.getByRole('menuitem', { name: /your profile/i });
  if (await yourProfileItem.first().isVisible({ timeout: 2000 }).catch(() => false)) {
    await yourProfileItem.first().click();
    return true;
  }

  const seeAllProfiles = page.getByRole('menuitem', { name: /see all profiles/i });
  if (await seeAllProfiles.isVisible({ timeout: 1500 }).catch(() => false)) {
    await seeAllProfiles.click();
    if (profileHint) {
      const namedProfile = page.getByRole('button').filter({ hasText: profileHint }).first();
      if (await namedProfile.isVisible({ timeout: 2000 }).catch(() => false)) {
        await namedProfile.click();
        return true;
      }
    }
    const continueButtons = page.locator('[aria-label^="Continue " i], [aria-label*="Continue " i][role="button"]');
    const count = await continueButtons.count();
    for (let i = 0; i < count; i++) {
      const button = continueButtons.nth(i);
      const label = ((await button.getAttribute('aria-label').catch(() => '')) || '').trim();
      if (!label || /use another profile|create new account/i.test(label)) {
        continue;
      }
      console.error(`[facebook] Switching to personal profile via ${label}`);
      await button.click();
      return true;
    }
  }

  const yourProfileButton = page.getByRole('button', { name: /your profile/i });
  if (await yourProfileButton.isVisible({ timeout: 1500 }).catch(() => false)) {
    await yourProfileButton.click();
    return true;
  }

  return false;
}

async function facebookCommentAsLabel(page) {
  const commentBox = page.locator('[role="textbox"][aria-label*="Comment as" i]').first();
  return ((await commentBox.getAttribute('aria-label').catch(() => '')) || '').trim();
}

async function isActingAsManagedPage(page, pageBrandPattern = null) {
  if (await isPageAdminMode(page) || await isActingAsPageOnFeed(page)) {
    return true;
  }

  const commentLabel = (await facebookCommentAsLabel(page)).toLowerCase();
  if (!commentLabel.includes('comment as')) {
    return false;
  }
  if (/comment as (you|your profile)\b/i.test(commentLabel)) {
    return false;
  }
  if (pageBrandPattern) {
    return pageBrandPattern.test(commentLabel);
  }

  return false;
}

async function tryOpenPersonalFeedsView(page, pageBrandPattern = null) {
  const feedsLink = page.getByRole('link', { name: /^feeds$/i }).first();
  if (await feedsLink.isVisible({ timeout: 2000 }).catch(() => false)) {
    console.error('[facebook] Opening personal Feeds view from sidebar');
    await feedsLink.click();
    await page.waitForLoadState('domcontentloaded', { timeout: 15000 }).catch(() => {});
    await page.waitForTimeout(1000).catch(() => {});
    if (!(await isActingAsManagedPage(page, pageBrandPattern))) {
      return true;
    }
  }

  const homeLink = page.getByRole('link', { name: /^home$/i }).first();
  if (await homeLink.isVisible({ timeout: 1500 }).catch(() => false)) {
    console.error('[facebook] Opening Home feed from sidebar');
    await homeLink.click();
    await page.waitForLoadState('domcontentloaded', { timeout: 15000 }).catch(() => {});
    await page.waitForTimeout(1000).catch(() => {});
    if (!(await isActingAsManagedPage(page, pageBrandPattern))) {
      return true;
    }
  }

  return false;
}

export async function ensureFacebookPersonalSession(page, personalProfileUrl = null, pageBrandPattern = null) {
  const destination = personalProfileUrl || 'https://www.facebook.com/';

  if (!(await isActingAsManagedPage(page, pageBrandPattern))) {
    return true;
  }

  console.error('[facebook] Switching to personal Facebook session');
  await page.goto(destination, { waitUntil: 'domcontentloaded', timeout: 25000 }).catch(() => {});
  await dismissStrayDialogs(page);

  if (await tryOpenPersonalFeedsView(page, pageBrandPattern)) {
    await page.waitForTimeout(500).catch(() => {});
  } else {
    await clickPersonalProfileInSwitcher(page, destination);
  }

  if (await isActingAsManagedPage(page, pageBrandPattern)) {
    console.error('[facebook] Could not fully switch out of page context; continuing with best-effort personal share');
  }

  return true;
}

function profileHintPatternFromUrl(url) {
  if (!url) {
    return null;
  }
  try {
    const parsed = new URL(url, 'https://www.facebook.com');
    const slug = parsed.pathname.replace(/^\/+|\/+$/g, '').split('/')[0];
    if (!slug || slug === 'profile.php') {
      return null;
    }
    const words = slug.split(/[.\-_]+/).filter(Boolean);
    if (words.length === 0) {
      return null;
    }
    return new RegExp(words.map((w) => w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('.*'), 'i');
  } catch {
    return null;
  }
}

export async function ensurePersonalProfileMode(page, personalProfileUrl = null, pageBrandPattern = null) {
  return ensureFacebookPersonalSession(page, personalProfileUrl, pageBrandPattern);
}

export async function findComposerTextbox(page) {
  const candidates = [
    page.locator('[role="dialog"] [role="textbox"]'),
    page.locator('[role="dialog"] div[contenteditable="true"]'),
    page.locator('[data-testid="status-attachment-mentions-input"]'),
    page.locator('div[contenteditable="true"][aria-label*="Create" i]'),
    page.locator('[role="textbox"][aria-label*="Create" i]'),
    page.locator('div[contenteditable="true"][aria-label*="What\'s on your mind" i]'),
    page.locator('[role="textbox"][aria-label*="What\'s on your mind" i]'),
    page.locator('div[contenteditable="true"][data-lexical-editor="true"]'),
    page.getByPlaceholder(/what'?s on your mind/i),
    page.locator('div[contenteditable="true"]').filter({ hasNot: page.locator('[aria-label*="comment" i]') }).first(),
  ];

  for (const locator of candidates) {
    const el = locator.first();
    if (await el.isVisible({ timeout: 3000 }).catch(() => false)) {
      return el;
    }
  }

  return null;
}

export async function typeIntoComposer(textbox, content) {
  await textbox.click();
  await textbox.fill('');
  await textbox.fill(content);
  const typed = (await textbox.innerText().catch(() => '')).trim();
  if (typed.length < Math.min(content.trim().length, 20)) {
    await textbox.click();
    await textbox.pressSequentially(content, { delay: TYPE_DELAY_MS });
  }
}

async function focusComposerDialog(page) {
  const createDialog = page.locator('[role="dialog"]').filter({ hasText: /create post/i });
  if (await createDialog.isVisible({ timeout: 2000 }).catch(() => false)) {
    return createDialog;
  }

  const composerDialog = page.locator('[role="dialog"]').filter({
    has: page.locator('[contenteditable="true"], [role="textbox"]'),
  });
  if (await composerDialog.isVisible({ timeout: 2000 }).catch(() => false)) {
    return composerDialog;
  }
  return null;
}

export async function waitForComposerReady(page, dialog, timeoutMs = 45000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const textbox = dialog.locator('[contenteditable="true"], [role="textbox"]').first();
    if (await textbox.isVisible({ timeout: 1000 }).catch(() => false)) {
      return textbox;
    }
  }

  return null;
}

async function dismissStuckComposer(page) {
  const createDialog = page.locator('[role="dialog"]').filter({ hasText: /create post|post settings|post preview/i });
  if (!(await createDialog.first().isVisible({ timeout: 1000 }).catch(() => false))) {
    return;
  }

  console.error('[facebook] Dismissing stuck composer dialog');
  const settingsDialog = page.locator('[role="dialog"]').filter({ hasText: /post settings/i });
  if (await settingsDialog.first().isVisible({ timeout: 500 }).catch(() => false)) {
    const discard = settingsDialog.getByRole('button', { name: /^discard$/i }).first();
    if (await discard.isVisible({ timeout: 1500 }).catch(() => false)) {
      await discard.click();
      return;
    }
  }

  const close = createDialog.first().locator(
    '[aria-label*="Close composer dialog" i], [aria-label*="Close" i]'
  ).first();
  if (await close.isVisible({ timeout: 1500 }).catch(() => false)) {
    await close.click();
    const discard = page.getByRole('button', { name: /^discard$/i }).first();
    if (await discard.isVisible({ timeout: 2000 }).catch(() => false)) {
      await discard.click();
    }
    return;
  }

  await page.keyboard.press('Escape');
}

export async function ensureCreatePostDialog(page) {
  await dismissPostViewer(page);

  if (await openProfessionalDashboardComposer(page)) {
    const dialog = await focusComposerDialog(page);
    if (dialog) {
      const textbox = await waitForComposerReady(page, dialog);
      if (textbox) {
        return dialog;
      }
    }
  }

  for (let attempt = 0; attempt < 2; attempt++) {
    let dialog = await focusComposerDialog(page);
    if (dialog) {
      const textbox = await waitForComposerReady(page, dialog);
      if (textbox) {
        return dialog;
      }
      await dismissStuckComposer(page);
    }

    const triggers = [
      page.getByText(/what'?s on your mind/i),
      page.locator('[aria-label*="Create a post" i]'),
      page.locator('[aria-label*="What\'s on your mind" i]'),
      page.getByRole('button', { name: /create a post/i }),
      page.getByRole('link', { name: /create a post/i }),
      page.getByRole('button', { name: /^post$/i }),
      page.locator('[role="button"]').filter({ hasText: /what'?s on your mind/i }),
      page.locator('nav, aside').getByRole('link', { name: /create a post/i }),
    ];

    for (const locator of triggers) {
      const el = locator.first();
      if (await el.isVisible({ timeout: 1500 }).catch(() => false)) {
        await el.scrollIntoViewIfNeeded();
        await el.click();
        dialog = await focusComposerDialog(page);
        if (dialog) {
          const textbox = await waitForComposerReady(page, dialog);
          if (textbox) {
            console.error(`[facebook] Create post dialog opened on attempt ${attempt + 1}`);
            return dialog;
          }
          await dismissStuckComposer(page);
        }
      }
    }
  }

  return null;
}

async function isExactPostButton(btn) {
  const aria = ((await btn.getAttribute('aria-label')) || '').trim();
  const text = ((await btn.innerText().catch(() => '')) || '').trim();
  const name = aria || text;
  if (!/^post$/i.test(name)) {
    return false;
  }
  if (/option/i.test(aria) || /option/i.test(text)) {
    return false;
  }
  return await btn.isVisible({ timeout: 500 }).catch(() => false);
}

async function clickExactPostButton(btn, page) {
  const label = ((await btn.getAttribute('aria-label')) || '').trim();
  const text = ((await btn.innerText().catch(() => '')) || '').trim();
  console.error(`[facebook] Clicking Post button (${label || text || 'unlabeled'})`);
  await btn.scrollIntoViewIfNeeded();
  await btn.click({ timeout: 15000 });
}

async function postSettingsDialog(page) {
  return page.locator('[role="dialog"]').filter({ hasText: /post settings/i }).first();
}

async function postSettingsIsPublishing(page) {
  const dialog = await postSettingsDialog(page);
  if (!(await dialog.isVisible({ timeout: 500 }).catch(() => false))) {
    return false;
  }
  return dialog.getByText(/^posting$/i).first()
    .isVisible({ timeout: 500 }).catch(() => false);
}

async function clickPostButton(scope, page) {
  const buttons = scope.locator('[role="button"]');
  const count = await buttons.count();
  let submitButton = null;

  for (let i = 0; i < count; i++) {
    const btn = buttons.nth(i);
    if (!(await isExactPostButton(btn))) {
      continue;
    }
    submitButton = btn;
  }

  if (submitButton) {
    await clickExactPostButton(submitButton, page);
    return true;
  }

  const roleButtons = scope.getByRole('button', { name: /^post$/i });
  const roleCount = await roleButtons.count();
  if (roleCount > 0) {
    const btn = roleButtons.last();
    if (await btn.isVisible({ timeout: 500 }).catch(() => false)) {
      await clickExactPostButton(btn, page);
      return true;
    }
  }

  const textButtons = scope.locator('div[role="button"]').filter({ hasText: /^post$/i });
  const textCount = await textButtons.count();
  for (let i = 0; i < textCount; i++) {
    const btn = textButtons.nth(i);
    if (await btn.isVisible({ timeout: 500 }).catch(() => false)) {
      await clickExactPostButton(btn, page);
      return true;
    }
  }

  return false;
}

async function findComposerDialog(page) {
  const createDialog = page.locator('[role="dialog"]').filter({ hasText: /create post|post preview|post settings/i });
  if (await createDialog.first().isVisible({ timeout: 1000 }).catch(() => false)) {
    return createDialog.first();
  }

  const composerDialog = page.locator('[role="dialog"]').filter({
    has: page.locator('[contenteditable="true"], [role="textbox"]'),
  }).filter({
    hasNot: page.getByText(/say something about this/i),
  }).filter({
    hasNot: page.getByRole('button', { name: /share now/i }),
  });
  if (await composerDialog.first().isVisible({ timeout: 1000 }).catch(() => false)) {
    return composerDialog.first();
  }

  return null;
}

export async function expandInlineComposerToModal(page) {
  const draftBox = page.locator('[role="main"] div').filter({ hasText: /.{20,}/ }).filter({
    has: page.locator('[contenteditable="true"], [role="textbox"]'),
  }).first();

  const inlineCandidates = [
    draftBox,
    page.locator('[role="main"] [contenteditable="true"]').first(),
    page.getByText(/what'?s on your mind/i),
    page.locator('[aria-label*="Create a post" i]'),
  ];

  for (const locator of inlineCandidates) {
    const el = locator.first();
    if (await el.isVisible({ timeout: 1500 }).catch(() => false)) {
      console.error('[facebook] Expanding inline composer to modal');
      await el.scrollIntoViewIfNeeded();
      await el.click();
      const dialog = await focusComposerDialog(page);
      if (dialog) {
        return dialog;
      }
      await el.dblclick().catch(() => {});
      const retryDialog = await focusComposerDialog(page);
      if (retryDialog) {
        return retryDialog;
      }
    }
  }

  return ensureCreatePostDialog(page);
}

export async function composerHasAttachedImage(page, dialog) {
  async function scopeShowsMediaAttachment(scope) {
    if (!(await scope.isVisible({ timeout: 500 }).catch(() => false))) {
      return false;
    }

    const mediaControls = scope.locator(
      '[aria-label*="Remove photo" i], [aria-label*="Remove video" i], [aria-label*="Remove media" i], [aria-label*="Remove image" i], [aria-label*="Edit photo" i], [aria-label*="Edit media" i], [aria-label*="Edit video" i], [aria-label="Edit" i], [aria-label^="Edit " i]'
    );
    if (await mediaControls.first().isVisible({ timeout: 500 }).catch(() => false)) {
      return true;
    }

    const editControls = scope.locator(
      '[role="button"][aria-label*="Edit" i], a[aria-label*="Edit" i], [role="link"][aria-label*="Edit" i]'
    );
    if (await editControls.first().isVisible({ timeout: 500 }).catch(() => false)) {
      return true;
    }

    const editText = scope.getByText(/^edit$/i);
    if (await editText.first().isVisible({ timeout: 500 }).catch(() => false)) {
      return true;
    }

    const photoSurfaces = scope.locator(
      '[data-visualcompletion="media-vc-image"], div[role="img"][style*="background-image"]'
    );
    if (await photoSurfaces.first().isVisible({ timeout: 500 }).catch(() => false)) {
      const box = await photoSurfaces.first().boundingBox().catch(() => null);
      if (box && box.width >= 100 && box.height >= 100) {
        return true;
      }
    }

    const blobImgs = scope.locator('img[src^="blob:"]');
    const blobCount = await blobImgs.count();
    for (let i = 0; i < blobCount; i++) {
      const box = await blobImgs.nth(i).boundingBox().catch(() => null);
      if (box && box.width > 80 && box.height > 80) {
        return true;
      }
    }

    const previewImgs = scope.locator(
      'div[role="presentation"] img[src*="scontent"], div[role="presentation"] img[src*="fbcdn"], img[src*="scontent"], img[src*="fbcdn"]'
    );
    const previewCount = await previewImgs.count();
    for (let i = 0; i < previewCount; i++) {
      const img = previewImgs.nth(i);
      const box = await img.boundingBox().catch(() => null);
      if (!box || box.width < 100 || box.height < 100) {
        continue;
      }
      const inHeader = await img.evaluate((node) => {
        let el = node;
        for (let depth = 0; depth < 8 && el; depth++) {
          const label = (el.getAttribute?.('aria-label') || '').toLowerCase();
          if (label.includes('profile picture') || label.includes('profile photo')) {
            return true;
          }
          el = el.parentElement;
        }
        return false;
      }).catch(() => false);
      if (!inHeader) {
        return true;
      }
    }

    return scope.evaluate((root) => {
      const isProfileLabel = (el) => {
        const label = (el?.getAttribute?.('aria-label') || '').toLowerCase();
        return label.includes('profile picture') || label.includes('profile photo');
      };

      const isInProfileHeader = (el) => {
        let node = el;
        for (let depth = 0; depth < 10 && node; depth++) {
          if (isProfileLabel(node)) {
            return true;
          }
          node = node.parentElement;
        }
        return false;
      };

      for (const el of root.querySelectorAll('[aria-label*="Edit" i], [aria-label*="Remove photo" i], [aria-label*="Remove image" i]')) {
        if (el.offsetParent !== null && !isInProfileHeader(el)) {
          const rect = el.getBoundingClientRect();
          if (rect.width > 0 && rect.height > 0) {
            return true;
          }
        }
      }

      for (const el of root.querySelectorAll('img, canvas, video, div')) {
        const rect = el.getBoundingClientRect();
        if (rect.width < 120 || rect.height < 120) {
          continue;
        }
        if (isInProfileHeader(el)) {
          continue;
        }
        if (el.tagName === 'IMG') {
          const src = el.src || '';
          if (src.includes('rsrc.php') || src.includes('composer/SATP')) {
            continue;
          }
          return true;
        }
        if (el.tagName === 'CANVAS' || el.tagName === 'VIDEO') {
          return true;
        }
        if (el.tagName === 'DIV') {
          const style = window.getComputedStyle(el);
          const hasBgImage = style.backgroundImage && style.backgroundImage !== 'none';
          const bg = style.backgroundColor || '';
          const hasSolidBg = bg && !bg.includes('rgba(0, 0, 0, 0)') && bg !== 'transparent';
          if (rect.width < 180 || rect.height < 180) {
            continue;
          }
          const label = (el.getAttribute?.('aria-label') || '').toLowerCase();
          const text = (el.textContent || '').trim();
          if (label.includes('background') || text === 'Aa') {
            continue;
          }
          if (hasBgImage || hasSolidBg) {
            return true;
          }
        }
      }
      return false;
    }).catch(() => false);
  }

  const scopes = [
    dialog,
    await findComposerDialog(page),
    page.locator('[role="dialog"]').filter({ hasText: /create post|post preview|post settings/i }),
    getAddToPostPicker(page),
    page.locator('[role="dialog"]'),
  ].filter(Boolean);

  const seen = new Set();
  for (const scope of scopes) {
    const key = String(scope);
    if (seen.has(key)) {
      continue;
    }
    seen.add(key);

    if (await scopeShowsMediaAttachment(scope)) {
      return true;
    }
  }

  return false;
}

/** True when the composer has a user-uploaded photo/video, not just an auto link preview. */
export async function composerHasUploadedPhoto(page, dialog) {
  async function scopeHasUpload(scope) {
    if (!(await scope.isVisible({ timeout: 500 }).catch(() => false))) {
      return false;
    }

    const removeBtn = scope.locator(
      '[aria-label*="Remove photo" i], [aria-label*="Remove image" i], [aria-label*="Remove media" i], [aria-label*="Remove video" i]'
    );
    if (await removeBtn.first().isVisible({ timeout: 500 }).catch(() => false)) {
      return true;
    }

    const editPhoto = scope.locator(
      '[aria-label*="Edit photo" i], [aria-label*="Edit media" i], [aria-label*="Edit video" i]'
    );
    if (await editPhoto.first().isVisible({ timeout: 500 }).catch(() => false)) {
      return true;
    }

    const blobImgs = scope.locator('img[src^="blob:"]');
    const blobCount = await blobImgs.count();
    for (let i = 0; i < blobCount; i++) {
      const box = await blobImgs.nth(i).boundingBox().catch(() => null);
      if (box && box.width > 80 && box.height > 80) {
        return true;
      }
    }

    return false;
  }

  const scopes = [
    dialog,
    await findComposerDialog(page),
    page.locator('[role="dialog"]').filter({ hasText: /create post|post preview|post settings/i }),
  ].filter(Boolean);

  for (const scope of scopes) {
    if (await scopeHasUpload(scope)) {
      return true;
    }
  }
  return false;
}

async function waitForComposerImage(page, dialog, timeoutMs = 15000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const freshDialog = (await findComposerDialog(page)) || dialog;
    if (await composerHasUploadedPhoto(page, freshDialog)) {
      return true;
    }
    await dismissImageEditor(page);
    await returnFromAddToPostPicker(page, freshDialog);
    await page.waitForTimeout(400).catch(() => {});
  }
  return false;
}

async function dismissImageEditor(page) {
  const editor = page.locator('[role="dialog"]')
    .filter({ hasText: /edit photo|crop photo|edit media/i })
    .filter({ hasNot: page.getByText(/add to your post/i) });
  if (!(await editor.isVisible({ timeout: 1000 }).catch(() => false))) {
    return;
  }

  const done = editor.getByRole('button', { name: /^(done|save|next)$/i });
  if (await done.first().isVisible({ timeout: 1500 }).catch(() => false)) {
    console.error('[facebook] Confirming image editor');
    await done.first().click();
    return;
  }

  const back = editor.locator('[aria-label*="Back" i]').first();
  if (await back.isVisible({ timeout: 1000 }).catch(() => false)) {
    await back.click();
  }
}

export async function attachImageToComposer(page, imagePath, dialog) {
  if (!imagePath) {
    return;
  }

  if (!fs.existsSync(imagePath)) {
    throw new Error(`Image file not found: ${imagePath}`);
  }

  if (await composerHasUploadedPhoto(page, dialog)) {
    console.error('[facebook] Composer already has uploaded photo; skipping upload');
    return dialog;
  }

  const createDialog = page.locator('[role="dialog"]').filter({ hasText: /create post/i });
  const scopes = [dialog, createDialog, page.locator('[role="dialog"]').filter({ hasText: /create post/i })];

  console.error('[facebook] Attaching image to composer');
  let attached = false;
  for (const scope of scopes) {
    if (await attachImage(page, imagePath, scope)) {
      attached = true;
      break;
    }
  }

  if (!attached) {
    const photoBtn = createDialog.getByRole('button', { name: /^photo\/video$/i }).first();
    if (await photoBtn.isVisible({ timeout: 1500 }).catch(() => false)) {
      try {
        const [chooser] = await Promise.all([
          page.waitForEvent('filechooser', { timeout: 10000 }),
          photoBtn.click({ force: true }),
        ]);
        await chooser.setFiles(imagePath);
        attached = true;
      } catch (e) {
        console.error('[facebook] Photo/video filechooser failed:', e.message);
      }
    }
  }

  if (!attached) {
    throw new Error('Could not attach image to Facebook post composer.');
  }

  await dismissImageEditor(page);
  await returnFromAddToPostPicker(page, dialog);
  await dismissImageEditor(page);
  if (!(await waitForComposerImage(page, dialog, 20000))) {
    throw new Error('Failed to attach image to Facebook post composer.');
  }

  console.error('[facebook] Image attached to composer');
  return dialog;
}

function getAddToPostPicker(page) {
  return page.locator('[role="dialog"]')
    .filter({ hasText: /photo\/video/i })
    .filter({ hasText: /tag people/i })
    .filter({ hasNot: page.getByText(/create post|what'?s on your mind/i) });
}

async function returnFromAddToPostPicker(page, dialog) {
  const picker = getAddToPostPicker(page);
  if (!(await picker.isVisible({ timeout: 800 }).catch(() => false))) {
    return;
  }

  if (await composerHasAttachedImage(page, dialog)) {
    console.error('[facebook] Returning from Add to your post picker to composer');
    const back = picker.locator('[aria-label*="Back" i]').first();
    if (await back.isVisible({ timeout: 1500 }).catch(() => false)) {
      await back.click();
      return;
    }
  }

  console.error('[facebook] Closing stray Add to your post picker');
  const close = picker.locator('[aria-label*="Close" i]').first();
  if (await close.isVisible({ timeout: 1000 }).catch(() => false)) {
    await close.click();
  }
}

async function dismissShareDialog(page) {
  const shareDialog = page.locator('[role="dialog"]').filter({ hasText: /^share$/i });
  if (await shareDialog.isVisible({ timeout: 1000 }).catch(() => false)) {
    console.error('[facebook] Closing accidental Share dialog');
    const close = shareDialog.locator('[aria-label*="Close" i]').first();
    if (await close.isVisible({ timeout: 1000 }).catch(() => false)) {
      await close.click();
    } else {
      await page.keyboard.press('Escape');
    }
  }
}

export async function dismissPromotionalModals(page) {
  let dismissed = false;

  const notNowButton = page.getByRole('button', { name: /not now/i });
  if (await notNowButton.first().isVisible({ timeout: 1000 }).catch(() => false)) {
    console.error('[facebook] Dismissing promotional modal via Not now button');
    await notNowButton.first().click();
    dismissed = true;
  }

  const promoDialog = page.locator('[role="dialog"]').filter({ hasText: /speak with people directly/i });
  if (!dismissed && await promoDialog.isVisible({ timeout: 500 }).catch(() => false)) {
    const notNowInDialog = promoDialog.getByText(/^not now$/i);
    if (await notNowInDialog.isVisible({ timeout: 1000 }).catch(() => false)) {
      console.error('[facebook] Dismissing Speak with people directly modal');
      await notNowInDialog.click();
      dismissed = true;
    } else {
      const promoClose = promoDialog.locator('[aria-label*="Close" i]').first();
      if (await promoClose.isVisible({ timeout: 1000 }).catch(() => false)) {
        await promoClose.click();
        dismissed = true;
      }
    }
  }

  if (dismissed) {
  }
}

export async function dismissStrayDialogs(page) {
  await dismissPromotionalModals(page);
  const settings = page.locator('[role="dialog"]').filter({ hasText: /post settings/i });
  while (await settings.isVisible({ timeout: 1000 }).catch(() => false)) {
    const publishReady = await settings.getByRole('button', { name: /^post$/i }).first()
      .isVisible({ timeout: 500 }).catch(() => false);
    if (publishReady) {
      return;
    }
    const back = settings.locator('[aria-label*="Back" i], [aria-label*="Close" i]').first();
    if (await back.isVisible({ timeout: 1000 }).catch(() => false)) {
      await back.click();
      continue;
    }
    await page.keyboard.press('Escape');
    break;
  }
}

export async function clickPublishInDialog(page, knownDialog = null, options = {}) {
  const requireImage = Boolean(options.requireImage);
  await dismissStrayDialogs(page);
  await dismissCoverPhotoEditor(page);
  await dismissShareDialog(page);

  let composerDialog = knownDialog;
  if (!composerDialog || !(await composerDialog.isVisible({ timeout: 1000 }).catch(() => false))) {
    composerDialog = await findComposerDialog(page);
  }
  if (!composerDialog) {
    composerDialog = await expandInlineComposerToModal(page);
  }
  if (!composerDialog) {
    throw new Error('Could not find create-post composer dialog before publishing.');
  }

  await returnFromAddToPostPicker(page, composerDialog);
  const hasImage = await composerHasUploadedPhoto(page, composerDialog);
  const next = composerDialog.getByRole('button', { name: /^next$/i });

  async function waitForPublishScreen(timeoutMs = 45000) {
    const deadline = Date.now() + timeoutMs;
    while (Date.now() < deadline) {
      if (await postSettingsIsPublishing(page)) {
        return 'publishing';
      }
      const settingsDialog = page.locator('[role="dialog"]').filter({ hasText: /post settings/i });
      if (await settingsDialog.first().isVisible({ timeout: 400 }).catch(() => false)) {
        return 'settings';
      }
      const previewDialog = await findComposerDialog(page);
      if (previewDialog) {
        const postBtn = previewDialog.getByRole('button', { name: /^post$/i }).first();
        if (await postBtn.isVisible({ timeout: 400 }).catch(() => false)) {
          return 'preview';
        }
      }
      await page.waitForTimeout(500).catch(() => {});
    }
    return null;
  }

  async function publishFromPreview() {
    await dismissPromotionalModals(page);

    const screen = await waitForPublishScreen();
    if (screen === 'publishing') {
      console.error('[facebook] Post settings already publishing');
      await waitForPublishDialogsToClose(page);
      await dismissPromotionalModals(page);
      await dismissShareDialog(page);
      return true;
    }

    if (screen === 'settings') {
      const settingsDialog = page.locator('[role="dialog"]').filter({ hasText: /post settings/i });
      console.error('[facebook] Waiting for Post settings dialog to finish loading');
      if (!(await waitForPostSettingsReady(page))) {
        throw new Error('Post settings dialog did not become ready to publish.');
      }
      console.error('[facebook] Publishing from Post settings dialog');
      if (requireImage && !(await composerHasUploadedPhoto(page, settingsDialog.first()))) {
        throw new Error('Image missing from Facebook post settings preview before publishing.');
      }
      if (!(await clickPostInPostSettings(page))) {
        throw new Error('Could not click Post in Post settings dialog.');
      }
      await waitForPublishDialogsToClose(page);
      await dismissPromotionalModals(page);
      await dismissShareDialog(page);
      return true;
    }

    if (screen === 'preview') {
      const previewDialog = await findComposerDialog(page);
      if (!previewDialog) {
        throw new Error('Create-post dialog closed unexpectedly after clicking Next.');
      }
      if (requireImage && !(await composerHasUploadedPhoto(page, previewDialog))) {
        throw new Error('Image missing from Facebook post preview before publishing.');
      }
      if (await clickPostButton(previewDialog, page)) {
        await dismissPromotionalModals(page);
        await dismissShareDialog(page);
        return true;
      }
    }

    return false;
  }

  if (requireImage) {
    if (!hasImage) {
      throw new Error('Image was not attached to the Facebook composer before publishing.');
    }
    if (!(await next.isVisible({ timeout: 3000 }).catch(() => false))) {
      console.error('[facebook] No Next button for image post; trying direct Post button');
      if (await clickPostButton(composerDialog, page)) {
        await dismissPromotionalModals(page);
        await dismissShareDialog(page);
        return;
      }
      throw new Error('Create-post composer did not offer Next for image post.');
    }
    if (!(await next.isEnabled().catch(() => true))) {
      throw new Error('Next button is disabled; image may not be attached.');
    }
    console.error('[facebook] Clicking Next in create-post composer (image post)');
    await next.click();
    if (!(await publishFromPreview())) {
      throw new Error('Could not publish image post from preview dialog.');
    }
    return;
  }

  if (hasImage && await next.isVisible({ timeout: 3000 }).catch(() => false)) {
    console.error('[facebook] Clicking Next in create-post composer (image post)');
    if (!(await next.isEnabled().catch(() => true))) {
      throw new Error('Next button is disabled in create-post composer.');
    }
    await next.click();
    if (await publishFromPreview()) {
      return;
    }
  }

  if (await clickPostButton(composerDialog, page)) {
    await dismissPromotionalModals(page);
    await dismissShareDialog(page);
    return;
  }

  if (await next.isVisible({ timeout: 5000 }).catch(() => false)) {
    console.error('[facebook] Clicking Next in create-post composer');
    await next.click();
    if (await publishFromPreview()) {
      return;
    }
  }

  await dismissShareDialog(page);
  throw new Error('Could not find Post button in create-post composer.');
}

export async function waitForComposerClose(page) {
  await waitForPublishDialogsToClose(page);
}

async function waitForPublishDialogsToClose(page) {
  const deadline = Date.now() + 45000;
  while (Date.now() < deadline) {
    await dismissPromotionalModals(page);
    await dismissShareDialog(page);

    const settingsOpen = await page.locator('[role="dialog"]').filter({ hasText: /post settings/i })
      .first().isVisible({ timeout: 500 }).catch(() => false);
    const settingsPublishing = settingsOpen && await postSettingsIsPublishing(page);
    const createOpen = await page.locator('[role="dialog"]').filter({ hasText: /create post|post preview/i })
      .first().isVisible({ timeout: 500 }).catch(() => false);
    const pickerOpen = await getAddToPostPicker(page).isVisible({ timeout: 500 }).catch(() => false);
    const promoOpen = await page.locator('[role="dialog"]').filter({ hasText: /speak with people directly/i })
      .isVisible({ timeout: 500 }).catch(() => false);

    if (!settingsOpen && !createOpen && !pickerOpen && !promoOpen) {
      return;
    }

    if (settingsPublishing) {
      continue;
    }

  }

  throw new Error('Publish dialog still open after clicking Post.');
}

async function clickPostInPostSettings(page) {
  const settingsDialog = await postSettingsDialog(page);
  if (!(await settingsDialog.isVisible({ timeout: 2000 }).catch(() => false))) {
    return false;
  }

  if (await postSettingsIsPublishing(page)) {
    console.error('[facebook] Post settings dialog already shows Publishing');
    return true;
  }

  await dismissPromotionalModals(page);

  if (!(await waitForPostSettingsReady(page))) {
    return false;
  }

  const submit = settingsDialog.getByRole('button', { name: /^post$/i }).last();
  if (await submit.isVisible({ timeout: 2000 }).catch(() => false)) {
    await clickExactPostButton(submit, page);
    if (await postSettingsIsPublishing(page)) {
      return true;
    }
    return !(await settingsDialog.isVisible({ timeout: 1500 }).catch(() => false));
  }

  return false;
}

async function waitForPostSettingsReady(page, timeoutMs = 45000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const settingsDialog = await postSettingsDialog(page);
    if (!(await settingsDialog.isVisible({ timeout: 500 }).catch(() => false))) {
      return false;
    }
    if (await postSettingsIsPublishing(page)) {
      return true;
    }
    const submit = settingsDialog.getByRole('button', { name: /^post$/i }).last();
    if (await submit.isVisible({ timeout: 500 }).catch(() => false)) {
      const enabled = await submit.isEnabled().catch(() => true);
      if (enabled) {
        return true;
      }
    }
    await page.waitForTimeout(500).catch(() => {});
  }
  return false;
}

export async function pageIsUnavailable(page) {
  return page.getByText(/this page isn't available|page isn't available|content isn't available/i).first()
    .isVisible({ timeout: 1000 }).catch(() => false);
}

function decodeFacebookRedirect(href) {
  try {
    const url = new URL(href, 'https://www.facebook.com');
    if (url.hostname.includes('l.facebook.com') && url.searchParams.has('u')) {
      return decodeURIComponent(url.searchParams.get('u') || '');
    }
  } catch {
    // ignore
  }
  return href;
}

function normalizeFacebookUrl(href) {
  if (href.startsWith('http')) {
    return href;
  }
  return `https://www.facebook.com${href.startsWith('/') ? '' : '/'}${href}`;
}

function normalizePostPermalink(url) {
  if (!url) {
    return url;
  }
  try {
    const parsed = new URL(url, 'https://www.facebook.com');
    for (const key of [...parsed.searchParams.keys()]) {
      if (/^__(cft|tn|s|x)__$/i.test(key) || key.startsWith('__')) {
        parsed.searchParams.delete(key);
      }
    }
    return parsed.toString();
  } catch {
    return url;
  }
}

function hrefLooksLikePostUrl(href) {
  if (!href) {
    return false;
  }
  const decoded = decodeFacebookRedirect(href);
  return /(posts\/|pfbid|story_fbid|permalink\.php|story\.php|photo\.php|fbid=|share\/p\/|multi_permalinks)/i.test(decoded);
}

function isSamePageUrl(href, pageUrl) {
  const decoded = decodeFacebookRedirect(href || '');
  if (hrefLooksLikePostUrl(decoded)) {
    return false;
  }
  try {
    const a = new URL(decoded, 'https://www.facebook.com');
    const b = new URL(pageUrl, 'https://www.facebook.com');
    if (a.pathname === b.pathname && a.search === b.search) {
      return true;
    }
    const aId = a.searchParams.get('id');
    const bId = b.searchParams.get('id');
    if (aId && bId && aId === bId && a.pathname.endsWith('profile.php')) {
      return true;
    }
  } catch {
    return false;
  }
  return false;
}

/** @returns {string|null} Numeric page id or vanity slug from a page bootstrap URL. */
export function pageKeyFromUrl(pageUrl) {
  try {
    const url = new URL(pageUrl, 'https://www.facebook.com');
    const id = url.searchParams.get('id');
    if (id) {
      return id;
    }
    const path = url.pathname.replace(/^\/|\/$/g, '');
    if (path && path !== 'profile.php' && !path.includes('/')) {
      return path;
    }
  } catch {
    // ignore
  }
  return null;
}

/**
 * Normalize a raw href from a feed card into a canonical Facebook post permalink.
 * @param {string} pageUrl
 * @param {string} href
 * @returns {string|null}
 */
export function canonicalizePostHref(pageUrl, href) {
  if (!href) {
    return null;
  }
  const decoded = decodeFacebookRedirect(href);
  const pageKey = pageKeyFromUrl(pageUrl);

  const boostMatch = decoded.match(/[?&]target_id=(\d+)[^#]*[?&]page_id=(\d+)|[?&]page_id=(\d+)[^#]*[?&]target_id=(\d+)/i);
  if (boostMatch) {
    const storyFbid = boostMatch[1] || boostMatch[4];
    const pageId = boostMatch[2] || boostMatch[3];
    if (storyFbid && pageId) {
      const permalink = new URL('https://www.facebook.com/permalink.php');
      permalink.searchParams.set('story_fbid', storyFbid);
      permalink.searchParams.set('id', pageId);
      return normalizePostPermalink(permalink.toString());
    }
  }

  const pfbidMatch = decoded.match(/(pfbid[A-Za-z0-9]+)/i);
  if (pfbidMatch && pageKey && /professional_dashboard|insights|post_id=/i.test(decoded)) {
    return normalizePostPermalink(`https://www.facebook.com/${pageKey}/posts/${pfbidMatch[1]}`);
  }

  const normalized = normalizeFacebookUrl(decoded);
  if (isDirectFacebookPostUrl(normalized) && !isPhotoFacebookPostUrl(normalized)) {
    return normalizePostPermalink(normalized);
  }

  try {
    const url = new URL(decoded, 'https://www.facebook.com');
    const storyFbid = url.searchParams.get('story_fbid');
    const pageId = url.searchParams.get('id') || pageKey;
    if (storyFbid && pageId) {
      const permalink = new URL('https://www.facebook.com/permalink.php');
      permalink.searchParams.set('story_fbid', storyFbid);
      permalink.searchParams.set('id', pageId);
      return normalizePostPermalink(permalink.toString());
    }

    if (pfbidMatch && pageKey) {
      return normalizePostPermalink(`https://www.facebook.com/${pageKey}/posts/${pfbidMatch[1]}`);
    }
  } catch {
    // ignore
  }

  return null;
}

export function isDirectFacebookPostUrl(url) {
  if (!url || /sk=posts/i.test(url)) {
    return false;
  }
  const decoded = decodeFacebookRedirect(url);
  if (/\/professional_dashboard\/|\/insights\//i.test(decoded)) {
    return false;
  }
  if (/photo\.php|[?&]fbid=/i.test(decoded) && !/\/posts\//i.test(decoded)) {
    return false;
  }
  return /\/(posts\/|permalink\.php|story\.php|watch\/|reel\/|share\/p\/)/i.test(decoded)
    || /pfbid[A-Za-z0-9]+/i.test(decoded)
    || /[?&](story_fbid|multi_permalinks)=/i.test(decoded);
}

function isPhotoFacebookPostUrl(url) {
  const decoded = decodeFacebookRedirect(String(url || ''));
  return /photo\.php|[?&]fbid=/i.test(decoded);
}

function resolveCapturedPostPermalink(pageUrl, rawUrl) {
  if (!rawUrl) {
    return null;
  }
  const fromHref = canonicalizePostHref(pageUrl, rawUrl);
  if (fromHref) {
    return fromHref;
  }
  const normalized = normalizeFacebookUrl(decodeFacebookRedirect(rawUrl));
  if (isDirectFacebookPostUrl(normalized) && !isPhotoFacebookPostUrl(normalized)) {
    return normalizePostPermalink(normalized);
  }
  return null;
}

function normalizePostTextHint(text) {
  return String(text || '').replace(/\s+/g, ' ').trim().toLowerCase();
}

function textHintPattern(textHint) {
  const snippet = normalizePostTextHint(textHint).slice(0, 48);
  if (!snippet) {
    return null;
  }
  return new RegExp(snippet.slice(0, 24).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i');
}

async function collectHrefCandidatesNearText(page, textHint) {
  const pattern = textHintPattern(textHint);
  if (!pattern) {
    return [];
  }

  const textNode = page.getByText(pattern).first();
  if (!(await textNode.isVisible({ timeout: 3000 }).catch(() => false))) {
    return [];
  }

  return textNode.evaluate((el) => {
    const out = [];
    let node = el;
    for (let depth = 0; depth < 14 && node; depth++) {
      node.querySelectorAll?.('a[href]').forEach((anchor) => {
        const href = anchor.href || anchor.getAttribute('href') || '';
        if (href) {
          out.push(href);
        }
      });
      node = node.parentElement;
    }
    return out;
  }).catch(() => []);
}

/**
 * Scan the page Posts feed for a primary post permalink (fallback when publish UI omits one).
 * @param {import('playwright').Page} page
 * @param {string} pageUrl
 * @param {{ textHint?: string|null }} [options]
 * @returns {Promise<string|null>}
 */
export async function capturePrimaryPostPermalinkFromFeed(page, pageUrl, options = {}) {
  const { textHint = null } = options;
  if (!facebookUrlsMatch(page.url(), pageUrl)) {
    await navigateToFacebookPage(page, pageUrl);
  } else {
    await ensureFacebookPageLoaded(page);
  }

  if (!(await isPageAdminMode(page))) {
    await ensurePageAdminMode(page);
  }

  await openPagePostsSurface(page, pageUrl);
  await page.waitForTimeout(1200).catch(() => {});

  if (textHint) {
    const nearLinks = await collectHrefCandidatesNearText(page, textHint);
    for (const href of nearLinks) {
      const resolved = canonicalizePostHref(pageUrl, href);
      if (resolved) {
        console.error(`[facebook] Captured primary post permalink near matching text: ${resolved}`);
        return resolved;
      }
    }
  }

  const main = page.locator('[role="main"]').first();
  const hint = textHint ? normalizePostTextHint(textHint).slice(0, 48) : null;
  const articles = main.locator('[role="article"]');
  const articleCount = await articles.count();

  for (let i = 0; i < Math.min(articleCount, 12); i++) {
    const article = articles.nth(i);
    if (hint) {
      const articleText = normalizePostTextHint(await article.innerText().catch(() => ''));
      if (articleText && !articleText.includes(hint.slice(0, 24))) {
        continue;
      }
    }

    const links = article.locator('a[href]');
    const linkCount = await links.count();
    for (let j = 0; j < linkCount; j++) {
      const href = await links.nth(j).getAttribute('href');
      const resolved = canonicalizePostHref(pageUrl, href || '');
      if (resolved) {
        console.error(`[facebook] Captured primary post permalink from feed: ${resolved}`);
        return resolved;
      }
    }
  }

  const allLinks = main.locator('a[href]');
  const allCount = await allLinks.count();
  for (let i = 0; i < Math.min(allCount, 60); i++) {
    const href = await allLinks.nth(i).getAttribute('href');
    const resolved = canonicalizePostHref(pageUrl, href || '');
    if (resolved) {
      console.error(`[facebook] Captured primary post permalink from page links: ${resolved}`);
      return resolved;
    }
  }

  return null;
}

/**
 * Resolve a primary post permalink using publish UI signals then feed scan.
 * @param {import('playwright').Page} page
 * @param {string} pageUrl
 * @param {{ textHint?: string|null }} [options]
 * @returns {Promise<string|null>}
 */
export async function resolveFacebookPrimaryPostPermalink(page, pageUrl, options = {}) {
  const fromUi = await capturePrimaryPostPermalinkLight(page, pageUrl);
  if (fromUi) {
    return fromUi;
  }

  return capturePrimaryPostPermalinkFromFeed(page, pageUrl, options);
}

/**
 * Read a post permalink from the current URL or publish success UI only (no feed scan).
 * @param {import('playwright').Page} page
 * @param {string} pageUrl
 * @returns {Promise<string|null>}
 */
export async function capturePrimaryPostPermalinkLight(page, pageUrl) {
  const fromCurrent = resolveCapturedPostPermalink(pageUrl, page.url());
  if (fromCurrent) {
    return fromCurrent;
  }

  const deadline = Date.now() + 12000;
  while (Date.now() < deadline) {
    await dismissPromotionalModals(page);

    const fromUrl = resolveCapturedPostPermalink(pageUrl, page.url());
    if (fromUrl) {
      return fromUrl;
    }

    const viewPostLabels = [/view (?:your )?post/i, /see post/i];
    for (const label of viewPostLabels) {
      const link = page.getByRole('link', { name: label }).first();
      if (await link.isVisible({ timeout: 200 }).catch(() => false)) {
        const href = await link.getAttribute('href');
        const resolved = resolveCapturedPostPermalink(pageUrl, href || '');
        if (resolved) {
          return resolved;
        }
      }
    }

    const meta = await page.evaluate(() => {
      const og = document.querySelector('meta[property="og:url"]');
      if (og?.getAttribute('content')) {
        return og.getAttribute('content');
      }
      const canonical = document.querySelector('link[rel="canonical"]');
      return canonical?.getAttribute('href') || '';
    }).catch(() => '');
    const fromMeta = resolveCapturedPostPermalink(pageUrl, meta);
    if (fromMeta) {
      return fromMeta;
    }

    const toast = page.locator('[role="alert"], [role="status"]')
      .filter({ hasText: /facebook\.com|copied|link/i }).first();
    const toastText = await toast.innerText({ timeout: 500 }).catch(() => '');
    const toastMatch = toastText.match(/https:\/\/[^\s]+/i);
    if (toastMatch) {
      const fromToast = resolveCapturedPostPermalink(pageUrl, toastMatch[0].trim());
      if (fromToast) {
        return fromToast;
      }
    }

    const dialogHrefs = await page.locator('[role="dialog"] a[href]').evaluateAll((els) =>
      els.map((el) => el.getAttribute('href')).filter(Boolean)
    ).catch(() => []);
    for (const href of dialogHrefs) {
      const fromDialog = resolveCapturedPostPermalink(pageUrl, href);
      if (fromDialog) {
        return fromDialog;
      }
    }

    await page.waitForTimeout(400);
  }

  return null;
}

const FEED_SHARE_BUTTON = /send this to friends or post it on your profile/i;
const FEED_SHARE_MENU = /share.*(profile|feed)|post.*(on )?your profile|send this to friends/i;

async function openPrimaryPostPermalinkModal(page, primaryPostUrl, options = {}) {
  const { skipWarmup = false, skipGoto = false } = options;
  if (!skipWarmup) {
    await warmupFacebookSession(page);
  }

  if (!skipGoto && !facebookUrlsMatch(page.url(), primaryPostUrl)) {
    console.error(`[facebook] Opening primary post permalink ${primaryPostUrl}`);
    await humanPause(page, 500, 1200);
    await page.goto(primaryPostUrl, { waitUntil: 'domcontentloaded', timeout: 25000 });
    await humanPause(page, 800, 1500);
  }

  const modal = page.locator('[role="dialog"]').filter({ hasText: /'s post/i }).first();
  await modal.waitFor({ state: 'visible', timeout: 10000 });
  await ensureFacebookPageLoaded(page);
  await dismissStrayDialogs(page);

  const feedShareBtn = modal.getByRole('button', { name: FEED_SHARE_BUTTON }).first();
  if (!(await feedShareBtn.isVisible({ timeout: 5000 }).catch(() => false))) {
    throw new Error('Send-to-profile share button not found on primary post modal.');
  }

  return modal;
}

async function findFeedShareDialog(page) {
  const byName = page.getByRole('dialog', { name: /^share$/i });
  if (await byName.isVisible({ timeout: 500 }).catch(() => false)) {
    return byName;
  }

  const byShareNow = page.locator('[role="dialog"]').filter({
    has: page.getByRole('button', { name: /^share now$/i }),
  }).first();
  if (await byShareNow.isVisible({ timeout: 500 }).catch(() => false)) {
    return byShareNow;
  }

  return null;
}

function isFeedShareMenuLabel(label) {
  const normalized = String(label || '').replace(/\s+/g, ' ').trim();
  if (!normalized) {
    return false;
  }
  if (/^share$/i.test(normalized)) {
    return true;
  }
  return FEED_SHARE_MENU.test(normalized);
}

async function isWrongShareDialog(dialog) {
  const hasShareNow = await dialog.getByRole('button', { name: /^share now$/i })
    .isVisible({ timeout: 1500 }).catch(() => false);
  if (hasShareNow) {
    return false;
  }
  return dialog.getByText(/send in messenger|whatsapp|copy link|your story/i)
    .first()
    .isVisible({ timeout: 1500 }).catch(() => false);
}

async function pickFeedShareMenuItem(page) {
  const preferred = [
    page.getByRole('menuitem', { name: /^share now$/i }),
    page.getByRole('button', { name: /^share now$/i }),
    page.getByRole('menuitem', { name: /post on your profile/i }),
    page.getByRole('menuitem', { name: /share to feed/i }),
    page.getByRole('menuitem', { name: /news feed/i }),
  ];
  for (const locator of preferred) {
    const item = locator.first();
    if (await item.isVisible({ timeout: 800 }).catch(() => false)) {
      console.error('[facebook] Opening feed share via preferred menu item');
      await item.click();
      await page.waitForTimeout(500).catch(() => {});
      if (await findFeedShareDialog(page)) {
        return true;
      }
      return true;
    }
  }

  const menuItems = page.locator('[role="menuitem"], [role="menu"] [role="button"]');
  const menuCount = Math.min(await menuItems.count(), 8);
  for (let i = 0; i < menuCount; i++) {
    const item = menuItems.nth(i);
    const label = ((await item.innerText().catch(() => '')) || '')
      + ' ' + ((await item.getAttribute('aria-label').catch(() => '')) || '');
    if (!isFeedShareMenuLabel(label)
      && !/share now|news feed|your profile|post on your profile|share to feed|send this to friends/i.test(label)) {
      continue;
    }
    console.error(`[facebook] Opening feed share via menu item: ${label.trim().slice(0, 80)}`);
    await item.click();
    await page.waitForTimeout(500).catch(() => {});
    if (await findFeedShareDialog(page)) {
      return true;
    }
    return true;
  }
  return false;
}

async function warmupFacebookSession(page) {
  if (!/facebook\.com/i.test(page.url())) {
    await page.goto('https://www.facebook.com/', { waitUntil: 'domcontentloaded', timeout: 25000 });
  }
  await humanPause(page, 1200, 2500);
  await page.evaluate(() => window.scrollBy(0, 150 + Math.random() * 250)).catch(() => {});
  await humanPause(page, 600, 1400);
  await ensureFacebookPageLoaded(page);
}

async function sharePrimaryPostToPersonalFeed(page, primaryPostUrl, options = {}) {
  const modal = await openPrimaryPostPermalinkModal(page, primaryPostUrl, options);

  const feedShareBtn = modal.getByRole('button', { name: FEED_SHARE_BUTTON }).first();
  console.error('[facebook] Clicking send-to-profile share on primary post');
  await feedShareBtn.scrollIntoViewIfNeeded().catch(() => {});
  await feedShareBtn.click({ force: true });
  await humanPause(page, 1000, 1800);

  let dialog = await findFeedShareDialog(page);
  if (!dialog) {
    await pickFeedShareMenuItem(page);
    dialog = await findFeedShareDialog(page);
  }
  if (!dialog) {
    throw new Error('Feed share dialog not found after clicking send-to-profile.');
  }

  await sharePostToFeed(page);
  await humanPause(page, 2000, 3500);
}

async function sharePostToFeed(page) {
  const dialog = await findFeedShareDialog(page);
  if (!dialog) {
    throw new Error('Feed share dialog not found.');
  }
  await dialog.waitFor({ state: 'visible', timeout: 10000 });

  if (await isWrongShareDialog(dialog)) {
    await dismissShareDialog(page);
    throw new Error('Opened external share menu instead of feed share dialog.');
  }

  const shareNowButton = dialog.getByRole('button', { name: /^share now$/i });
  if (!(await shareNowButton.isVisible({ timeout: 8000 }).catch(() => false))) {
    await dismissShareDialog(page);
    throw new Error('Share now button not found in Facebook feed share dialog.');
  }

  const sharingToFeed = dialog.getByText(/sharing to feed/i);
  if (!(await sharingToFeed.isVisible({ timeout: 3000 }).catch(() => false))) {
    const feedTarget = dialog.getByRole('button', { name: /^feed$/i }).first();
    if (await feedTarget.isVisible({ timeout: 2000 }).catch(() => false)) {
      await feedTarget.click();
    }
  }

  const shareNow = dialog.getByRole('button', { name: /^share now$/i });
  if (!(await shareNow.isEnabled().catch(() => true))) {
    throw new Error('Share now button is disabled in Facebook share dialog.');
  }

  console.error('[facebook] Clicking Share now');
  await shareNow.click();
  await dialog.waitFor({ state: 'hidden', timeout: 10000 }).catch(() => {});
}

function primaryBrandPattern(input) {
  const explicitBrand = input?.primaryPageBrand || input?.primary_page_brand;
  if (explicitBrand) {
    return new RegExp(String(explicitBrand).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i');
  }

  const url = input?.primaryPageUrl || input?.primaryPostUrl || '';
  try {
    const path = new URL(url, 'https://www.facebook.com').pathname.replace(/^\//, '');
    const slug = path.split('/')[0];
    if (slug && slug !== 'profile.php' && slug !== 'pages') {
      return new RegExp(slug.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i');
    }
  } catch {
    // ignore
  }
  return null;
}

export async function repostFacebookPost(page, input) {
  if (input.dryRun) {
    const primaryPostUrl = input.primaryPostUrl;
    if (!primaryPostUrl) {
      throw new Error('primaryPostUrl is required for Facebook repost dry-run');
    }
    if (!input.targetPageUrl && !input.personalContextUrl) {
      throw new Error('targetPageUrl is required for Facebook repost dry-run');
    }
    if (!input._personalSessionReady) {
      await ensurePersonalProfileMode(page, input.personalContextUrl || input.targetPageUrl);
    }
    await openPrimaryPostPermalinkModal(page, primaryPostUrl, {
      skipWarmup: Boolean(input._personalSessionReady),
      skipGoto: facebookUrlsMatch(page.url(), primaryPostUrl),
    });
    return { verified: true, startUrl: page.url() };
  }

  const t0 = Date.now();
  const elapsed = () => `+${((Date.now() - t0) / 1000).toFixed(1)}s`;
  const brandPattern = primaryBrandPattern(input);

  const personalUrl = input.targetPageUrl || input.personalContextUrl || 'https://www.facebook.com/';
  if (!input._personalSessionReady) {
    await ensureFacebookPersonalSession(page, personalUrl, brandPattern);
  }

  const shareTargetUrl = input.primaryPostUrl;
  if (!shareTargetUrl) {
    throw new Error('Primary post URL is required for reposting.');
  }
  const successUrl = facebookRepostSuccessUrl(input);

  console.error(`[facebook] [repost ${elapsed()}] Sharing to personal feed`);
  await sharePrimaryPostToPersonalFeed(page, shareTargetUrl, {
    skipWarmup: Boolean(input._personalSessionReady),
    skipGoto: facebookUrlsMatch(page.url(), shareTargetUrl),
  });
  return { postUrl: successUrl, verified: true };
}
