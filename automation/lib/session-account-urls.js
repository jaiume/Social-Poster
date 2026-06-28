/** @type {readonly string[]} */
const FACEBOOK_RESERVED_SLUGS = [
  'www', 'login', 'home', 'groups', 'events', 'watch', 'marketplace',
  'profile.php', 'pages', 'gaming', 'reels', 'stories',
];

/**
 * @param {string} locator
 * @returns {string}
 */
export function facebookBootstrapFromLocator(locator) {
  const key = String(locator || '').trim();
  if (/^\d+$/.test(key)) {
    return `https://www.facebook.com/profile.php?id=${encodeURIComponent(key)}`;
  }
  return `https://www.facebook.com/${encodeURIComponent(key)}`;
}

/**
 * @param {string} locator
 * @returns {string}
 */
export function linkedinBootstrapFromLocator(locator) {
  const key = String(locator || '').trim();
  const prefixed = key.match(/^(company|showcase)\/(.+)$/i);
  if (prefixed) {
    const kind = prefixed[1].toLowerCase();
    const id = prefixed[2].trim();
    if (!id) {
      throw new Error('LinkedIn page id is required.');
    }
    return linkedinAdminUrl(kind, id);
  }

  return linkedinAdminUrl('showcase', key);
}

/**
 * @param {'company'|'showcase'} kind
 * @param {string} id
 * @returns {string}
 */
function linkedinAdminUrl(kind, id) {
  if (kind !== 'company' && kind !== 'showcase') {
    throw new Error('LinkedIn page kind must be company or showcase.');
  }
  return `https://www.linkedin.com/${kind}/${encodeURIComponent(id)}/admin/page-posts/published`;
}

/**
 * @param {string} platform
 * @param {string} accountKind
 * @param {string|null} [subPageId]
 * @returns {string}
 */
export function bootstrapUrl(platform, accountKind, subPageId = null) {
  if (accountKind === 'sub') {
    const id = String(subPageId || '').trim();
    if (!id) {
      throw new Error('sub_page_id is required for sub accounts.');
    }
    if (platform === 'facebook') {
      return facebookBootstrapFromLocator(id);
    }
    if (platform === 'linkedin') {
      return linkedinBootstrapFromLocator(id);
    }
    throw new Error(`Unsupported platform: ${platform}`);
  }

  if (platform === 'facebook') {
    return 'https://www.facebook.com/';
  }
  if (platform === 'linkedin') {
    return 'https://www.linkedin.com/feed/';
  }
  throw new Error(`Unsupported platform: ${platform}`);
}

/**
 * @param {string} platform
 * @returns {string}
 */
export function personalContextUrl(platform) {
  return bootstrapUrl(platform, 'root');
}

/**
 * @param {string} input
 * @returns {string}
 */
export function normalizeFacebookSubPageLocator(input) {
  const raw = String(input || '').trim();
  if (!raw) {
    throw new Error('Page ID or username is required.');
  }

  if (/^https?:\/\//i.test(raw) || raw.includes('facebook.com')) {
    return parseFacebookPageUrl(raw);
  }

  if (raw.startsWith('profile.php')) {
    return parseFacebookPageUrl(`https://www.facebook.com/${raw.replace(/^\//, '')}`);
  }

  if (/^\d+$/.test(raw)) {
    return raw;
  }

  if (!/^[A-Za-z0-9][A-Za-z0-9._-]*$/.test(raw)) {
    throw new Error('Invalid Facebook page username.');
  }

  if (FACEBOOK_RESERVED_SLUGS.includes(raw.toLowerCase())) {
    throw new Error('Invalid Facebook page username.');
  }

  return raw;
}

/**
 * @param {string} platform
 * @param {string} input
 * @returns {string}
 */
export function normalizeSubPageLocator(platform, input) {
  if (platform === 'facebook') {
    return normalizeFacebookSubPageLocator(input);
  }
  if (platform === 'linkedin') {
    return normalizeLinkedInSubPageLocator(input);
  }
  throw new Error(`Unsupported platform: ${platform}`);
}

/**
 * @param {string} input
 * @returns {string}
 */
function parseFacebookPageUrl(input) {
  const url = /^https?:\/\//i.test(input) ? input : `https://${input.replace(/^\//, '')}`;
  let parsed;
  try {
    parsed = new URL(url);
  } catch {
    throw new Error('Invalid Facebook page URL.');
  }

  if (!parsed.hostname.toLowerCase().endsWith('facebook.com')) {
    throw new Error('URL must be a facebook.com page link.');
  }

  const path = parsed.pathname.replace(/^\/|\/$/g, '');
  if (path === 'profile.php' || path.endsWith('/profile.php')) {
    const id = (parsed.searchParams.get('id') || '').trim();
    if (!id || !/^\d+$/.test(id)) {
      throw new Error('Facebook page URL must include a numeric page id.');
    }
    return id;
  }

  if (!path || path.includes('/')) {
    throw new Error('Invalid Facebook page URL path.');
  }

  const blocked = ['groups', 'events', 'watch', 'marketplace', 'photo.php', 'story.php', 'share'];
  const lower = path.toLowerCase();
  if (blocked.some((segment) => lower.startsWith(segment))) {
    throw new Error('URL must point to a Facebook page, not a group or event.');
  }

  if (FACEBOOK_RESERVED_SLUGS.includes(lower)) {
    throw new Error('Invalid Facebook page URL.');
  }

  if (!/^[A-Za-z0-9][A-Za-z0-9._-]*$/.test(path)) {
    throw new Error('Invalid Facebook page username in URL.');
  }

  return path;
}

/**
 * @param {string} input
 * @returns {string}
 */
function normalizeLinkedInSubPageLocator(input) {
  const raw = String(input || '').trim();
  if (/^https?:\/\//i.test(raw) || raw.includes('linkedin.com')) {
    return parseLinkedInPageUrl(raw);
  }

  const prefixed = raw.match(/^(company|showcase)[:/](.+)$/i);
  if (prefixed) {
    return canonicalLinkedInLocator(prefixed[1].toLowerCase(), prefixed[2].trim());
  }

  if (/^\d+$/.test(raw)) {
    throw new Error(
      'LinkedIn numeric page id is ambiguous; use company/ID, showcase/ID, or paste the full admin URL.'
    );
  }

  if (!/^[A-Za-z0-9][A-Za-z0-9._-]*$/.test(raw)) {
    throw new Error('Invalid LinkedIn page id.');
  }

  return canonicalLinkedInLocator('showcase', raw);
}

/**
 * @param {string} input
 * @returns {string}
 */
function parseLinkedInPageUrl(input) {
  const url = /^https?:\/\//i.test(input) ? input : `https://${input.replace(/^\//, '')}`;
  const match = url.match(/\/(company|showcase)\/([^/?#]+)/i);
  if (match) {
    return canonicalLinkedInLocator(match[1].toLowerCase(), decodeURIComponent(match[2]));
  }
  throw new Error(
    'LinkedIn URL must be a company or showcase admin link (e.g. /company/…/admin/ or /showcase/…/admin/).'
  );
}

/**
 * @param {'company'|'showcase'} kind
 * @param {string} id
 * @returns {string}
 */
function canonicalLinkedInLocator(kind, id) {
  const key = String(id || '').trim();
  if (!key) {
    throw new Error('LinkedIn page id is required.');
  }
  if (kind !== 'company' && kind !== 'showcase') {
    throw new Error('LinkedIn page kind must be company or showcase.');
  }
  if (!/^[A-Za-z0-9][A-Za-z0-9._-]*$/.test(key)) {
    throw new Error('Invalid LinkedIn page id.');
  }
  return `${kind}/${key}`;
}

/**
 * @param {{ platform: string, accountKind?: string, account_kind?: string, subPageId?: string, sub_page_id?: string }} account
 * @returns {string}
 */
export function bootstrapUrlForAccount(account) {
  return bootstrapUrl(
    account.platform,
    account.accountKind || account.account_kind,
    account.subPageId ?? account.sub_page_id ?? null
  );
}
