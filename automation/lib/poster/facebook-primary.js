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

/**
 * @param {import('./types.js').PosterInput} posterInput
 * @returns {Promise<import('./types.js').PosterResult>}
 */
export async function publishFacebookPrimaryPost(page, posterInput) {
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
