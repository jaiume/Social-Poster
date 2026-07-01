import {
  openPostComposer,
  findPostEditor,
  typeIntoEditor,
  attachImageToComposer,
  restoreComposerContentIfLost,
  clickLinkedInPost,
  waitForDialogClose,
  canonicalizeLinkedInPostHref,
  isDirectLinkedInPostUrl,
  linkedInUrlsMatch,
  resolveLinkedInPrimaryPostPermalink,
} from '../linkedin.js';
import { publishSubmittedResult, dryRunResult } from './publish-result.js';

/**
 * @param {import('./types.js').PosterInput} posterInput
 * @returns {Promise<import('./types.js').PosterResult>}
 */
export async function publishLinkedInPrimaryPost(page, posterInput) {
  const text = posterInput.text ?? posterInput.content ?? '';
  const pageUrl = posterInput.pageUrl || posterInput.operatorStartUrl;
  const accountKind = posterInput.accountKind || posterInput.account_kind || 'sub';
  if (!pageUrl) {
    throw new Error('pageUrl is required for LinkedIn primary post');
  }

  if (!linkedInUrlsMatch(page.url(), pageUrl)) {
    await page.goto(pageUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
  }

  if (!(await openPostComposer(page))) {
    throw new Error('Could not open LinkedIn post composer.');
  }
  const editor = await findPostEditor(page);
  if (!editor) {
    throw new Error('Composer textbox not found.');
  }
  await typeIntoEditor(editor, text);

  if (posterInput.imagePath) {
    await attachImageToComposer(page, posterInput.imagePath);
    const editorAfterImage = await findPostEditor(page);
    if (editorAfterImage) {
      await restoreComposerContentIfLost(editorAfterImage, text);
    }
  }

  if (posterInput.dryRun) {
    return dryRunResult(page);
  }

  let postUrl = (await clickLinkedInPost(page)) || (await waitForDialogClose(page));
  postUrl = canonicalizeLinkedInPostHref(postUrl, page.url())
    || (isDirectLinkedInPostUrl(page.url()) ? page.url() : null);
  if (postUrl) {
    console.error(`[linkedin] Captured primary post permalink: ${postUrl}`);
  } else {
    console.error('[linkedin] Primary post permalink not captured from success dialog; scanning feed.');
    postUrl = await resolveLinkedInPrimaryPostPermalink(page, pageUrl, {
      textHint: text,
      accountKind,
    });
    if (postUrl) {
      console.error(`[linkedin] Recovered primary post permalink from feed: ${postUrl}`);
    }
  }

  return publishSubmittedResult(page, postUrl);
}
