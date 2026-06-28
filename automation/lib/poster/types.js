/**
 * @typedef {object} PosterInput
 * @property {import('playwright').Page} page
 * @property {string} text
 * @property {string} [content] Legacy alias for text
 * @property {string} [imagePath]
 * @property {string} pageUrl Target profile/page URL
 * @property {string} [operatorStartUrl]
 * @property {string} [accountKind] root | sub
 * @property {string} [account_kind] Legacy alias
 * @property {string} [subPageId]
 * @property {string} [personalContextUrl] Repost bootstrap URL (personal feed)
 * @property {string} [primaryPageUrl] Repost: posting account bootstrap URL
 * @property {string} [primaryPageBrand] Repost: primary page display name for actor exclusion
 * @property {string} [personalProfileUrl] Repost: personal /in/ profile for actor detection
 * @property {boolean} [dryRun]
 * @property {object} [input] Passthrough for platform libs (screenshot paths, etc.)
 */

/**
 * @typedef {object} PosterResult
 * @property {string} [postUrl]
 * @property {string} [resolvedStartUrl]
 * @property {string} [startUrl]
 * @property {boolean} [verified]
 */

export {};
