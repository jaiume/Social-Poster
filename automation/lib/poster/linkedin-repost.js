import { repostLinkedInPost, linkedInRepostSuccessUrl } from '../linkedin.js';
import { dryRunResult, repostSubmittedResult } from './publish-result.js';

/**
 * @param {import('./types.js').PosterInput} posterInput
 * @returns {Promise<import('./types.js').PosterResult>}
 */
export async function publishLinkedInRepost(page, posterInput) {
  const input = {
    ...posterInput.input,
    dryRun: Boolean(posterInput.dryRun),
    content: posterInput.text ?? posterInput.content ?? '',
    primaryPostUrl: posterInput.primaryPostUrl,
    primaryPageUrl: posterInput.primaryPageUrl,
    primaryPageBrand: posterInput.primaryPageBrand,
    targetPageUrl: posterInput.targetPageUrl || posterInput.pageUrl,
    pageUrl: posterInput.pageUrl,
    personalContextUrl: posterInput.personalContextUrl,
    _bootstrapReady: true,
  };

  const result = await Promise.race([
    repostLinkedInPost(page, input),
    new Promise((_, reject) => {
      setTimeout(() => reject(new Error('LinkedIn repost timed out after 180s')), 180000);
    }),
  ]);

  if (posterInput.dryRun) {
    return dryRunResult(page);
  }

  const targetPageUrl = result.postUrl ?? linkedInRepostSuccessUrl(input);
  return repostSubmittedResult(targetPageUrl, page);
}
