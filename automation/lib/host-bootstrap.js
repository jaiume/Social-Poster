import {
  ensurePageAdminMode,
  ensurePersonalProfileMode,
  dismissCoverPhotoEditor,
  pageIsUnavailable,
  ensureFacebookPageLoaded,
} from './facebook.js';
import { assertLinkedInSession } from './linkedin.js';
import { getSuggestedPreSteps } from './flow-pre-steps.js';

async function runFacebookPreStep(page, step, pageUrl, pageBrandPattern = null, personalContextUrl = null) {
  if (step === 'assert_session') {
    // Fails fast with a clear, specific error (session expired, checkpoint,
    // 2FA, reCAPTCHA, page unavailable) before falling into the composer/page
    // discovery retries, which have no way to distinguish "stale session" from
    // "unfamiliar UI" and can burn a lot of time before failing unclearly.
    await ensureFacebookPageLoaded(page);
    return;
  }
  if (step === 'abort_if_unavailable') {
    if (await pageIsUnavailable(page)) {
      throw new Error('Facebook page is not available at this URL.');
    }
    return;
  }
  if (step === 'ensure_page_admin') {
    await ensurePageAdminMode(page);
    return;
  }
  if (step === 'ensure_personal_profile') {
    const personalUrl = personalContextUrl || pageUrl;
    await ensurePersonalProfileMode(page, personalUrl, pageBrandPattern);
    return;
  }
  if (step === 'dismiss_cover_photo') {
    await dismissCoverPhotoEditor(page);
    return;
  }
  if (step === 'leave_page_admin') {
    const personalUrl = personalContextUrl || pageUrl;
    await ensurePersonalProfileMode(page, personalUrl, pageBrandPattern);
  }
}

function pageBrandPatternFromInput(input) {
  const brand = input?.primaryPageBrand || input?.primary_page_brand;
  if (!brand) {
    return null;
  }
  return new RegExp(String(brand).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i');
}

async function runLinkedInPreStep(page, step) {
  if (step === 'assert_session') {
    await assertLinkedInSession(page);
  }
}

/**
 * Run host pre_steps before publish/bootstrap.
 * @param {import('playwright').Page} page
 * @param {string} platform
 * @param {string[]} steps
 * @param {string} pageUrl
 * @param {RegExp|null} pageBrandPattern
 */
export async function runHostPreSteps(page, platform, steps, pageUrl, pageBrandPattern = null, personalContextUrl = null) {
  for (const step of steps) {
    if (platform === 'facebook') {
      await runFacebookPreStep(page, step, pageUrl, pageBrandPattern, personalContextUrl);
    } else {
      await runLinkedInPreStep(page, step);
    }
  }
}

/**
 * Navigate to the operator start URL and run host pre_steps.
 * @param {import('playwright').Page} page
 * @param {{ platform?: string, action?: string, pageUrl?: string, operatorStartUrl?: string, targetPageUrl?: string }} input
 */
export async function bootstrapHostPage(page, input) {
  const platform = input.platform;
  const action = input.action ?? 'post';
  const pageUrl = input.pageUrl || input.operatorStartUrl || input.targetPageUrl;
  const accountKind = input.accountKind || input.account_kind || 'sub';
  if (!platform) {
    throw new Error('platform is required for host bootstrap');
  }
  if (!pageUrl) {
    throw new Error('pageUrl or operatorStartUrl is required for host bootstrap');
  }

  await page.goto(pageUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });

  const steps = getSuggestedPreSteps(platform, action, accountKind);
  const pageBrandPattern = pageBrandPatternFromInput(input);
  const personalContextUrl = input.personalContextUrl
    || input.personal_context_url
    || input.targetPageUrl
    || null;
  await runHostPreSteps(page, platform, steps, pageUrl, pageBrandPattern, personalContextUrl);
}

/** @deprecated use runHostPreSteps */
export const runDiscoverPreSteps = runHostPreSteps;
