import {
  navigateToFacebookPage,
  ensurePageAdminMode,
  isPageAdminMode,
  facebookUrlsMatch,
  ensureFacebookPageLoaded,
  resolveFacebookPrimaryPostPermalink,
} from '../facebook.js';
import { publishSubmittedResult } from './publish-result.js';

/**
 * @param {import('playwright').Page} page
 * @param {import('./types.js').PosterInput} posterInput
 * @returns {Promise<import('./types.js').PosterResult>}
 */
export async function resolveFacebookPrimaryPostUrl(page, posterInput) {
  const text = posterInput.text ?? posterInput.content ?? '';
  const pageUrl = posterInput.pageUrl || posterInput.operatorStartUrl;
  const accountKind = posterInput.accountKind || posterInput.account_kind || 'sub';
  if (!pageUrl) {
    throw new Error('pageUrl is required to resolve Facebook primary post URL');
  }

  if (!facebookUrlsMatch(page.url(), pageUrl)) {
    await navigateToFacebookPage(page, pageUrl);
  } else {
    await ensureFacebookPageLoaded(page);
  }

  if (accountKind === 'sub' && !(await isPageAdminMode(page))) {
    await ensurePageAdminMode(page);
  }

  const postUrl = await resolveFacebookPrimaryPostPermalink(page, pageUrl, { textHint: text });
  if (!postUrl) {
    throw new Error('Could not resolve Facebook primary post permalink from the page feed.');
  }

  console.error(`[facebook] Resolved primary post permalink: ${postUrl}`);
  return publishSubmittedResult(page, postUrl);
}
