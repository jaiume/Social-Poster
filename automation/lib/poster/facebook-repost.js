import { repostFacebookPost, facebookRepostSuccessUrl } from '../facebook.js';
import { dryRunResult, repostSubmittedResult } from './publish-result.js';

/**
 * @param {import('./types.js').PosterInput} posterInput
 * @returns {Promise<import('./types.js').PosterResult>}
 */
export async function publishFacebookRepost(page, posterInput) {
  const input = {
    dryRun: Boolean(posterInput.dryRun),
    content: posterInput.text ?? posterInput.content ?? '',
    primaryPostUrl: posterInput.primaryPostUrl,
    primaryPageUrl: posterInput.primaryPageUrl,
    primaryPageBrand: posterInput.primaryPageBrand,
    targetPageUrl: posterInput.targetPageUrl || posterInput.pageUrl,
    pageUrl: posterInput.pageUrl,
    personalContextUrl: posterInput.personalContextUrl,
    _personalSessionReady: true,
    ...(posterInput.input ?? {}),
  };

  const result = await Promise.race([
    repostFacebookPost(page, input),
    new Promise((_, reject) => {
      setTimeout(() => reject(new Error('Facebook repost timed out after 90s')), 90000);
    }),
  ]);

  if (posterInput.dryRun) {
    return dryRunResult(page);
  }

  const successUrl = result.postUrl ?? facebookRepostSuccessUrl(input);
  return repostSubmittedResult(successUrl, page);
}
