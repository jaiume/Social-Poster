import {
  navigateToFacebookPage,
  ensurePageAdminMode,
  isPageAdminMode,
  facebookUrlsMatch,
  ensureFacebookPageLoaded,
  ensureCreatePostDialog,
  expandInlineComposerToModal,
  openPagePostsSurface,
  ensurePageReadyForComposer,
  waitForComposerReady,
  typeIntoComposer,
  attachImageToComposer,
  clickPublishInDialog,
  waitForComposerClose,
  findComposerTextbox,
  capturePrimaryPostPermalinkLight,
  resolveFacebookPrimaryPostPermalink,
} from '../facebook.js';
import { publishSubmittedResult, dryRunResult } from './publish-result.js';
import { raceWithTimeout } from '../timing.js';

/**
 * Overall wall-clock budget for the whole primary-post flow (composer discovery,
 * typing, image attach, publish, permalink capture). The various helpers in
 * facebook.js each retry with their own timeouts (up to 45s apiece) to cope with
 * Facebook's UI variability; in an unrecognized page state those can chain
 * together and approach the host's hard 180s script timeout, which kills the
 * process with no diagnostics. Racing against a smaller internal budget instead
 * throws a clear, catchable error (screenshot + structured errorCode) well
 * before that happens. Mirrors the equivalent guard in facebook-repost.js.
 */
const PRIMARY_POST_INTERNAL_TIMEOUT_MS = 120000;

/**
 * @param {import('./types.js').PosterInput} posterInput
 * @returns {Promise<import('./types.js').PosterResult>}
 */
export async function publishFacebookPrimaryPost(page, posterInput) {
  return raceWithTimeout(
    runPublishFacebookPrimaryPost(page, posterInput),
    PRIMARY_POST_INTERNAL_TIMEOUT_MS,
    `Facebook primary post automation exceeded its internal time budget of ${PRIMARY_POST_INTERNAL_TIMEOUT_MS / 1000}s.`
  );
}

async function runPublishFacebookPrimaryPost(page, posterInput) {
  const text = posterInput.text ?? posterInput.content ?? '';
  const pageUrl = posterInput.pageUrl || posterInput.operatorStartUrl;
  const accountKind = posterInput.accountKind || posterInput.account_kind || 'sub';
  const pageBrandPattern = posterInput.primaryPageBrand
    ? new RegExp(String(posterInput.primaryPageBrand).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i')
    : null;
  const requireImage = Boolean(posterInput.imagePath);
  if (!pageUrl) {
    throw new Error('pageUrl is required for Facebook primary post');
  }

  if (!facebookUrlsMatch(page.url(), pageUrl)) {
    await navigateToFacebookPage(page, pageUrl);
  } else {
    await ensureFacebookPageLoaded(page);
  }
  if (accountKind === 'sub') {
    if (!(await isPageAdminMode(page))) {
      await ensurePageAdminMode(page);
    }
    if (await page.getByRole('button', { name: /switch now/i }).isVisible({ timeout: 1500 }).catch(() => false)) {
      throw new Error(
        'Facebook Page requires admin mode. Open the page in Facebook, click Switch Now, then retry publishing.'
      );
    }
    await ensurePageReadyForComposer(page, pageUrl, pageBrandPattern);
  }

  let dialog = await ensureCreatePostDialog(page);
  if (!dialog && accountKind === 'sub') {
    await ensurePageReadyForComposer(page, pageUrl, pageBrandPattern);
    dialog = await ensureCreatePostDialog(page);
  }
  if (!dialog) {
    dialog = await expandInlineComposerToModal(page);
  }
  if (!dialog) {
    throw new Error('Could not open Facebook create post dialog.');
  }

  const textbox = await waitForComposerReady(page, dialog) || await findComposerTextbox(page);
  if (!textbox) {
    throw new Error('Composer textbox not found.');
  }
  await typeIntoComposer(textbox, text);

  if (posterInput.imagePath) {
    await attachImageToComposer(page, posterInput.imagePath, dialog);
  }

  if (posterInput.dryRun) {
    return dryRunResult(page);
  }

  await clickPublishInDialog(page, dialog, { requireImage });
  await waitForComposerClose(page, dialog).catch(() => {});

  const postUrl = await resolveFacebookPrimaryPostPermalink(page, pageUrl, { textHint: text });
  if (postUrl) {
    console.error(`[facebook] Captured primary post permalink: ${postUrl}`);
  }

  return publishSubmittedResult(page, postUrl);
}
