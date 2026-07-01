import {
  linkedInUrlsMatch,
  resolveLinkedInPrimaryPostPermalink,
} from '../linkedin.js';
import { publishSubmittedResult } from './publish-result.js';

/**
 * @param {import('playwright').Page} page
 * @param {import('./types.js').PosterInput} posterInput
 * @returns {Promise<import('./types.js').PosterResult>}
 */
export async function resolveLinkedInPrimaryPostUrl(page, posterInput) {
  const text = posterInput.text ?? posterInput.content ?? '';
  const pageUrl = posterInput.pageUrl || posterInput.operatorStartUrl;
  const accountKind = posterInput.accountKind || posterInput.account_kind || 'sub';
  if (!pageUrl) {
    throw new Error('pageUrl is required to resolve LinkedIn primary post URL');
  }

  if (!linkedInUrlsMatch(page.url(), pageUrl)) {
    await page.goto(pageUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
  }

  const postUrl = await resolveLinkedInPrimaryPostPermalink(page, pageUrl, {
    textHint: text,
    accountKind,
  });
  if (!postUrl) {
    throw new Error('Could not resolve LinkedIn primary post permalink from the page feed.');
  }

  console.error(`[linkedin] Resolved primary post permalink: ${postUrl}`);
  return publishSubmittedResult(page, postUrl);
}
