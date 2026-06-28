/**
 * @param {import('playwright').Page} page
 * @param {string|null} [postUrl]
 * @returns {import('./types.js').PosterResult}
 */
export function publishSubmittedResult(page, postUrl = null) {
  return {
    postUrl,
    resolvedStartUrl: page.url(),
    verified: true,
  };
}

/**
 * @param {string} contextUrl Personal feed/home URL after repost
 * @param {import('playwright').Page} [page]
 * @returns {import('./types.js').PosterResult}
 */
export function repostSubmittedResult(contextUrl, page = null) {
  return {
    postUrl: contextUrl,
    resolvedStartUrl: page?.url() ?? null,
    verified: true,
  };
}

/**
 * @param {import('playwright').Page} page
 * @returns {import('./types.js').PosterResult}
 */
export function dryRunResult(page) {
  const url = page.url();
  return {
    verified: true,
    startUrl: url,
    resolvedStartUrl: url,
  };
}
