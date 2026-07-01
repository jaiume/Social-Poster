import { repostFacebookPost, facebookRepostSuccessUrl } from '../facebook.js';
import { dryRunResult, repostSubmittedResult } from './publish-result.js';
import { raceWithTimeout } from '../timing.js';

// Includes headroom for the post-share timeline verification step (up to ~20s),
// which confirms the repost actually landed rather than trusting the share
// dialog closing alone. The outer PHP-level hard timeout for repost actions is
// 300s, so this still leaves a comfortable margin.
const REPOST_INTERNAL_TIMEOUT_MS = 120000;

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

  const result = await raceWithTimeout(
    repostFacebookPost(page, input),
    REPOST_INTERNAL_TIMEOUT_MS,
    `Facebook repost timed out after ${REPOST_INTERNAL_TIMEOUT_MS / 1000}s.`
  );

  if (posterInput.dryRun) {
    return dryRunResult(page);
  }

  const successUrl = result.postUrl ?? facebookRepostSuccessUrl(input);
  return repostSubmittedResult(successUrl, page);
}
