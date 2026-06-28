import { publishFacebookPrimaryPost } from './facebook-primary.js';
import { publishFacebookRepost } from './facebook-repost.js';
import { resolveFacebookPrimaryPostUrl } from './facebook-resolve-primary.js';
import { publishLinkedInPrimaryPost } from './linkedin-primary.js';
import { publishLinkedInRepost } from './linkedin-repost.js';

export { publishFacebookPrimaryPost } from './facebook-primary.js';
export { publishFacebookRepost } from './facebook-repost.js';
export { resolveFacebookPrimaryPostUrl } from './facebook-resolve-primary.js';
export { publishLinkedInPrimaryPost } from './linkedin-primary.js';
export { publishLinkedInRepost } from './linkedin-repost.js';

/** @type {Record<string, (page: import('playwright').Page, input: import('./types.js').PosterInput) => Promise<import('./types.js').PosterResult>>} */
export const POSTER_ACTIONS = {
  'facebook.post': publishFacebookPrimaryPost,
  'facebook.resolvePrimary': resolveFacebookPrimaryPostUrl,
  'facebook.repost': publishFacebookRepost,
  'linkedin.post': publishLinkedInPrimaryPost,
  'linkedin.repost': publishLinkedInRepost,
};

/**
 * @param {string} action
 * @param {import('playwright').Page} page
 * @param {import('./types.js').PosterInput} input
 */
export async function dispatchPosterAction(action, page, input) {
  const handler = POSTER_ACTIONS[action];
  if (!handler) {
    throw new Error(`Unknown poster action: ${action}`);
  }
  return handler(page, input);
}
