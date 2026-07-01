import { repostLinkedInPost, linkedInRepostSuccessUrl } from '../linkedin.js';
import { dryRunResult, repostSubmittedResult } from './publish-result.js';
import { raceWithTimeout } from '../timing.js';

const REPOST_INTERNAL_TIMEOUT_MS = 180000;

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

  const result = await raceWithTimeout(
    repostLinkedInPost(page, input),
    REPOST_INTERNAL_TIMEOUT_MS,
    `LinkedIn repost timed out after ${REPOST_INTERNAL_TIMEOUT_MS / 1000}s.`
  );

  if (posterInput.dryRun) {
    return dryRunResult(page);
  }

  const targetPageUrl = result.postUrl ?? linkedInRepostSuccessUrl(input);
  return repostSubmittedResult(targetPageUrl, page);
}
