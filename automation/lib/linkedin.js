import { TYPE_DELAY_MS } from './timing.js';

export async function screenshot(page, input, prefix) {
  const screenshotPath = `${input.screenshotDir}/${prefix}-${Date.now()}.png`;
  await page.screenshot({ path: screenshotPath, fullPage: false });
  return screenshotPath;
}

export async function detectLinkedInSecurityChallenge(page) {
  const recaptcha = await page.locator(
    'iframe[src*="recaptcha"], iframe[src*="captcha"], [id*="captcha"], [class*="recaptcha"]'
  ).first().isVisible({ timeout: 1000 }).catch(() => false);
  const challengeText = await page.getByText(
    /security verification|quick security check|unusual activity|verify it['']s you|let['']s do a quick security|checkpoint|not a robot/i
  ).first().isVisible({ timeout: 1000 }).catch(() => false);
  const puzzle = await page.locator(
    'iframe[src*="arkose"], iframe[src*="funcaptcha"], [data-test-id*="captcha"]'
  ).first().isVisible({ timeout: 800 }).catch(() => false);

  return { recaptcha, challengeText, puzzle };
}

export async function assertLinkedInSession(page) {
  const url = page.url();
  if (/\/login|checkpoint|authwall|challenge/i.test(url)) {
    if (/\/checkpoint|challenge/i.test(url)) {
      throw new Error('LinkedIn security verification required. Log in again from Sessions and complete 2FA.');
    }
    throw new Error('LinkedIn session expired. Log in again from Sessions.');
  }

  const security = await detectLinkedInSecurityChallenge(page);
  if (security.recaptcha || security.challengeText || security.puzzle) {
    throw new Error(
      'LinkedIn CAPTCHA or security challenge detected. Re-capture the session from Sessions using a normal browser.'
    );
  }

  const loginPassword = page.locator(
    'input[name="session_password"], input#password, input[autocomplete="current-password"]'
  ).first();
  const welcomeBack = page.getByText(/^welcome back$/i);
  if (await loginPassword.isVisible({ timeout: 1500 }).catch(() => false)) {
    throw new Error('LinkedIn session expired. Log in again from Sessions.');
  }
  if (await welcomeBack.isVisible({ timeout: 1000 }).catch(() => false)
    && await loginPassword.isVisible({ timeout: 500 }).catch(() => false)) {
    throw new Error('LinkedIn session expired. Log in again from Sessions.');
  }

  const marketingHome = page.getByText(/^welcome to your professional community$/i);
  const signIn = page.getByRole('link', { name: /^sign in$/i });
  if (await marketingHome.isVisible({ timeout: 800 }).catch(() => false)
    && await signIn.isVisible({ timeout: 800 }).catch(() => false)) {
    throw new Error('LinkedIn session expired. Log in again from Sessions.');
  }

  const otp = page.locator(
    'input[autocomplete="one-time-code"], input[name="pin"], input#input__email_verification_pin'
  );
  if (await otp.first().isVisible({ timeout: 800 }).catch(() => false)) {
    throw new Error('LinkedIn two-factor authentication required. Log in again from Sessions and complete 2FA.');
  }
}

async function openOrganizationAdminComposer(page) {
  if (!/\/(showcase|company)\/[^/]+\/admin\//i.test(page.url())) {
    return false;
  }

  if (await findPostEditor(page)) {
    console.error('[linkedin] Org admin composer already open');
    return true;
  }

  const pagePostsNav = [
    page.getByRole('link', { name: /^page posts$/i }),
    page.getByRole('button', { name: /^page posts$/i }),
    page.locator('a, button, [role="tab"]').filter({ hasText: /^page posts$/i }),
  ];
  for (const locator of pagePostsNav) {
    const el = locator.first();
    if (await el.isVisible({ timeout: 2000 }).catch(() => false)) {
      console.error('[linkedin] Opening Page posts tab');
      await el.click();
      await page.waitForLoadState('domcontentloaded', { timeout: 10000 }).catch(() => {});
      break;
    }
  }

  const createOpeners = [
    { name: 'create-a-post-button', locator: page.getByRole('button', { name: /create a post/i }) },
    { name: 'create-a-post-text', locator: page.getByText(/create a post/i) },
    { name: 'create-a-post-aria', locator: page.locator('[aria-label*="Create a post" i]') },
    { name: 'start-a-post-button', locator: page.getByRole('button', { name: /start a post/i }) },
  ];
  for (const { name, locator } of createOpeners) {
    if (await findPostEditor(page)) {
      return true;
    }
    const el = locator.first();
    if (await el.isVisible({ timeout: 3000 }).catch(() => false)) {
      console.error(`[linkedin] Opening org admin composer via ${name}`);
      await el.scrollIntoViewIfNeeded();
      await el.click();
      await page.waitForTimeout(400).catch(() => {});
      if (await findPostEditor(page)) {
        return true;
      }
    }
  }

  return false;
}

export async function openPostComposer(page) {
  await page.waitForLoadState('domcontentloaded');

  if (await findPostEditor(page)) {
    console.error('[linkedin] Post composer already open');
    return true;
  }

  if (await openOrganizationAdminComposer(page)) {
    return true;
  }

  const startPostOpeners = [
    { name: 'start-a-post-button', locator: page.getByRole('button', { name: /start a post/i }) },
    { name: 'aria-start-post', locator: page.locator('[aria-label*="Start a post" i]') },
    { name: 'start-a-post-text', locator: page.getByText(/start a post/i) },
    { name: 'share-update', locator: page.getByText(/share an update/i) },
    { name: 'write-article', locator: page.getByRole('button', { name: /write article/i }) },
  ];

  for (const { name, locator } of startPostOpeners) {
    if (await findPostEditor(page)) {
      return true;
    }
    const el = locator.first();
    if (await el.isVisible({ timeout: 2000 }).catch(() => false)) {
      console.error(`[linkedin] Opening composer via ${name}`);
      await el.scrollIntoViewIfNeeded();
      await el.click();
      await page.waitForTimeout(400).catch(() => {});
      if (await findPostEditor(page)) {
        return true;
      }
    }
  }

  const createOpeners = [
    { name: 'create-text', locator: page.getByText('+ Create', { exact: true }) },
    { name: 'create-button', locator: page.locator('button, [role="button"]').filter({ hasText: /^\+?\s*Create$/i }) },
    { name: 'create-aria', locator: page.locator('[aria-label*="Create" i]').first() },
    { name: 'sidebar-create', locator: page.locator('aside button, nav button').filter({ hasText: /create/i }).first() },
  ];

  for (const { name, locator } of createOpeners) {
    const el = locator.first();
    if (await el.isVisible({ timeout: 3000 }).catch(() => false)) {
      console.error(`[linkedin] Opening composer via admin ${name}`);
      await el.scrollIntoViewIfNeeded();
      await el.click();

      const postMenuItems = [
        page.getByRole('button', { name: /start a post/i }),
        page.getByText(/start a post/i),
        page.locator('[role="dialog"], [role="menu"]').getByText(/start a post/i),
        page.getByRole('menuitem', { name: /start a post/i }),
        page.getByRole('menuitem', { name: /^post$/i }),
        page.getByRole('button', { name: /^post$/i }),
        page.getByRole('link', { name: /^post$/i }),
        page.locator('[role="menu"] *').filter({ hasText: /^post$/i }),
      ];

      for (const menuItem of postMenuItems) {
        const item = menuItem.first();
        if (await item.isVisible({ timeout: 2500 }).catch(() => false)) {
          console.error('[linkedin] Selected Post from Create menu');
          await item.click();
          return true;
        }
      }

      if (await findPostEditor(page)) {
        return true;
      }
    }
  }

  return false;
}

async function proceedPastImageEditor(page) {
  const next = page.locator('[role="dialog"]').getByRole('button', { name: /^next$/i }).last();
  if (await next.isVisible({ timeout: 3000 }).catch(() => false)) {
    console.error('[linkedin] Advancing past image editor');
    await next.click();
  }

  const done = page.locator('[role="dialog"]').getByRole('button', { name: /^(done|save)$/i }).last();
  if (await done.isVisible({ timeout: 1500 }).catch(() => false)) {
    console.error('[linkedin] Finishing image editor');
    await done.click();
  }

  const credentialsDismiss = page.getByText(/content credentials/i).locator('xpath=ancestor::*[1]').getByRole('button').first();
  if (await credentialsDismiss.isVisible({ timeout: 1000 }).catch(() => false)) {
    await credentialsDismiss.click().catch(() => {});
  }
}

async function composerHasAttachedImage(page, scope = null) {
  const scopes = [];
  if (scope) {
    scopes.push(scope);
  }
  scopes.push(
    page.locator('[data-test-modal-id="sharebox"]'),
    page.locator('[role="dialog"]').filter({
      has: page.locator('[contenteditable="true"], [role="textbox"]'),
    }),
  );

  for (const dialog of scopes) {
    if (!(await dialog.isVisible({ timeout: 500 }).catch(() => false))) {
      continue;
    }

    const removeMedia = dialog.locator(
      '[aria-label*="Remove" i], [aria-label*="Delete" i], [aria-label*="Edit media" i], [aria-label*="Edit image" i]'
    );
    if (await removeMedia.first().isVisible({ timeout: 800 }).catch(() => false)) {
      return true;
    }

    const previewImgs = dialog.locator(
      'img[src^="blob:"], img[src*="media.licdn.com"], img[src*="licdn.com/dms/image"], img[src*="static.licdn.com"]'
    );
    const count = await previewImgs.count();
    for (let i = 0; i < count; i++) {
      const box = await previewImgs.nth(i).boundingBox().catch(() => null);
      if (box && box.width > 80 && box.height > 80) {
        return true;
      }
    }

    const hasLargePreview = await dialog.evaluate((root) => {
      for (const el of root.querySelectorAll('img, video, canvas')) {
        const rect = el.getBoundingClientRect();
        if (rect.width < 120 || rect.height < 120) {
          continue;
        }
        const label = (el.getAttribute?.('aria-label') || '').toLowerCase();
        if (label.includes('profile') || label.includes('logo') || label.includes('company')) {
          continue;
        }
        if (el.tagName === 'IMG') {
          const src = (el.getAttribute('src') || '').toLowerCase();
          if (src.includes('ghost') || src.includes('profile-displayphoto')) {
            continue;
          }
        }
        return true;
      }
      return false;
    }).catch(() => false);
    if (hasLargePreview) {
      return true;
    }
  }

  const sharebox = page.locator('[data-test-modal-id="sharebox"]');
  if (await sharebox.isVisible({ timeout: 300 }).catch(() => false)) {
    const hasShareboxMedia = await sharebox.evaluate((root) => {
      for (const el of root.querySelectorAll('img, video, canvas, [role="img"]')) {
        const rect = el.getBoundingClientRect();
        if (rect.width < 100 || rect.height < 100) {
          continue;
        }
        const label = (el.getAttribute?.('aria-label') || '').toLowerCase();
        if (label.includes('profile') || label.includes('logo') || label.includes('company')) {
          continue;
        }
        if (el.tagName === 'IMG') {
          const src = (el.getAttribute('src') || '').toLowerCase();
          if (src.includes('ghost') || src.includes('profile-displayphoto') || src.includes('company-logo')) {
            continue;
          }
        }
        return true;
      }
      return false;
    }).catch(() => false);
    if (hasShareboxMedia) {
      return true;
    }
  }

  return false;
}

async function dismissSaveDraftPrompt(page) {
  const draftPrompt = page.locator('[role="dialog"]').filter({ hasText: /save this post as a draft/i });
  if (!(await draftPrompt.isVisible({ timeout: 500 }).catch(() => false))) {
    return false;
  }

  console.error('[linkedin] Dismissing save-as-draft prompt (keeping composer open)');
  const close = draftPrompt.locator(
    'button.artdeco-modal__dismiss, [aria-label*="Dismiss" i], [aria-label*="Close" i]'
  ).first();
  if (await close.isVisible({ timeout: 1000 }).catch(() => false)) {
    await close.click();
  } else {
    // Escape cancels the leave attempt and returns to the open composer; Discard would lose content.
    await page.keyboard.press('Escape').catch(() => {});
  }
  return true;
}

async function dismissComposerOverlays(page) {
  for (let i = 0; i < 3; i++) {
    if (!(await dismissSaveDraftPrompt(page))) {
      break;
    }
  }

  const editArticle = page.locator('[role="dialog"]').filter({ hasText: /edit article data/i });
  if (await editArticle.isVisible({ timeout: 800 }).catch(() => false)) {
    console.error('[linkedin] Dismissing Edit article data modal');
    const back = editArticle.getByRole('button', { name: /^back$/i });
    const close = editArticle.locator('[aria-label*="Dismiss" i], [aria-label*="Close" i]').first();
    if (await back.isVisible({ timeout: 1000 }).catch(() => false)) {
      await back.click();
    } else if (await close.isVisible({ timeout: 1000 }).catch(() => false)) {
      await close.click();
    } else {
      await page.keyboard.press('Escape').catch(() => {});
    }
  }

  const credentials = page.getByText(/content credentials/i);
  if (await credentials.isVisible({ timeout: 500 }).catch(() => false)) {
    const dismiss = page.locator('[role="dialog"]').getByRole('button', { name: /not now|dismiss|close/i }).first();
    if (await dismiss.isVisible({ timeout: 1000 }).catch(() => false)) {
      await dismiss.click().catch(() => {});
    }
  }
}

async function uploadImageToComposer(page, imagePath, scope = null) {
  const dialog = scope ?? await focusComposerDialog(page);
  const roots = [dialog, page.locator('[data-test-modal-id="sharebox"]'), page].filter(Boolean);

  for (const root of roots) {
    if (root !== page && !(await root.isVisible({ timeout: 500 }).catch(() => false))) {
      continue;
    }

    const imageInputs = root.locator('input[type="file"][accept*="image"], input[type="file"]');
    const inputCount = await imageInputs.count();
    for (let i = 0; i < inputCount; i++) {
      try {
        await imageInputs.nth(i).setInputFiles(imagePath);
        return true;
      } catch {
        // try next input
      }
    }

    const mediaButtons = [
      root.getByRole('button', { name: /^photo$/i }),
      root.getByRole('button', { name: /^photo\/video$/i }),
      root.locator('[aria-label="Add a photo"]'),
      root.locator('[aria-label*="Add a photo" i]'),
      root.locator('[aria-label*="Add media" i]'),
      root.locator('[aria-label*="Photo/video" i]'),
      root.locator('[aria-label*="Image" i]'),
      root.locator('.share-actions__entry-button button').first(),
      root.locator('button').filter({ has: page.locator('li-icon[type="image"], svg') }).first(),
      root.locator('[data-test-share-creation-state] button').filter({ has: page.locator('svg') }).first(),
    ];

    for (const btn of mediaButtons) {
      const el = btn.first();
      if (!(await el.isVisible({ timeout: 1200 }).catch(() => false))) {
        continue;
      }
      try {
        const [fileChooser] = await Promise.all([
          page.waitForEvent('filechooser', { timeout: 8000 }),
          el.click(),
        ]);
        await fileChooser.setFiles(imagePath);
        return true;
      } catch {
        // try next trigger
      }
    }
  }

  return false;
}

async function waitForComposerImage(page, scope, timeoutMs = 20000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    await dismissComposerOverlays(page);
    const composer = (await focusComposerDialog(page)) ?? scope;
    if (await composerHasAttachedImage(page, composer ?? undefined)) {
      return true;
    }
  }
  return false;
}

export async function attachImageToComposer(page, imagePath, scope = null) {
  if (!imagePath) {
    return scope;
  }

  await dismissComposerOverlays(page);
  const dialog = scope ?? await focusComposerDialog(page);

  if (await composerHasAttachedImage(page, dialog ?? undefined)) {
    console.error('[linkedin] Composer already has attached image');
    return dialog;
  }

  console.error('[linkedin] Attaching image to composer');
  let uploaded = await uploadImageToComposer(page, imagePath, dialog ?? undefined);
  if (!uploaded) {
    throw new Error('Could not trigger LinkedIn image upload controls.');
  }

  await proceedPastImageEditor(page);

  if (!(await waitForComposerImage(page, dialog, 30000))) {
    throw new Error('Failed to attach image to LinkedIn post composer.');
  }

  await dismissComposerOverlays(page);
  console.error('[linkedin] Image attached to composer');
  const refreshed = await focusComposerDialog(page);
  return refreshed ?? dialog;
}

async function editorContainsSnippet(editor, content) {
  const snippet = content.trim().slice(0, 25);
  if (!snippet) {
    return true;
  }
  const text = (await editor.innerText().catch(() => '')).trim().toLowerCase();
  const needle = snippet.slice(0, 15).toLowerCase();
  return text.length >= Math.min(snippet.length, 10) && text.includes(needle);
}

export async function restoreComposerContentIfLost(editor, content) {
  if (await editorContainsSnippet(editor, content)) {
    console.error('[linkedin] Composer text still present after image attach');
    return;
  }

  console.error('[linkedin] Restoring composer text without clearing media');
  await editor.click({ force: true });
  await editor.pressSequentially(content, { delay: TYPE_DELAY_MS });
}

async function focusComposerDialog(page) {
  const sharebox = page.locator('[data-test-modal-id="sharebox"]');
  if (await sharebox.isVisible({ timeout: 2000 }).catch(() => false)) {
    return sharebox;
  }

  const composerDialog = page.locator('[role="dialog"]').filter({
    has: page.locator('[contenteditable="true"], [role="textbox"]'),
  });
  if (await composerDialog.isVisible({ timeout: 2000 }).catch(() => false)) {
    return composerDialog;
  }
  return null;
}

export async function findPostEditor(page) {
  const candidates = [
    page.locator('[data-test-modal-id="sharebox"] [contenteditable="true"]'),
    page.locator('[data-test-modal-id="sharebox"] [role="textbox"]'),
    page.locator('[role="dialog"] [contenteditable="true"]').filter({
      hasNot: page.locator('[aria-placeholder*="comment" i], [data-placeholder*="comment" i], [aria-label*="comment" i]'),
    }),
    page.locator('[role="dialog"] [role="textbox"]').filter({
      hasNot: page.locator('[aria-placeholder*="comment" i], [data-placeholder*="comment" i], [aria-label*="comment" i]'),
    }),
    page.locator('.share-box [contenteditable="true"]'),
    page.locator('.ql-editor').filter({
      hasNot: page.locator('[aria-placeholder*="comment" i], [data-placeholder*="comment" i], [aria-label*="comment" i]'),
    }),
    page.locator('[data-placeholder*="post" i]'),
    page.locator('[aria-label*="Text editor for creating content" i]').filter({
      hasNot: page.locator('[aria-placeholder*="comment" i]'),
    }),
  ];

  for (const locator of candidates) {
    const el = locator.first();
    if (await el.isVisible({ timeout: 3000 }).catch(() => false)) {
      return el;
    }
  }

  return null;
}

export async function typeIntoEditor(editor, content) {
  await editor.click({ force: true });
  await editor.fill('');
  await editor.fill(content);
  const typed = (await editor.innerText().catch(() => '')).trim();
  if (typed.length < Math.min(content.trim().length, 20)) {
    await editor.click();
    await editor.pressSequentially(content, { delay: TYPE_DELAY_MS });
  }
}

export async function clickLinkedInPost(page) {
  const existingSuccess = await dismissPostSuccessModal(page);
  if (existingSuccess.published) {
    return existingSuccess.postUrl;
  }

  const dialogScopes = [
    page.locator('[data-test-modal-id="sharebox"]'),
    page.locator('[role="dialog"]').filter({
      has: page.locator('[contenteditable="true"], [role="textbox"]'),
    }),
  ];

  for (const scope of dialogScopes) {
    if (!(await scope.first().isVisible({ timeout: 800 }).catch(() => false))) {
      continue;
    }
    const candidate = scope.getByRole('button', { name: /^(post|publish)$/i }).last();
    const deadline = Date.now() + 60000;
    while (Date.now() < deadline) {
      if (!(await candidate.isVisible({ timeout: 800 }).catch(() => false))) {
        break;
      }
      if (await candidate.isEnabled().catch(() => false)) {
        await candidate.click();
        return null;
      }
      await page.waitForTimeout(500);
    }
    if (await candidate.isVisible({ timeout: 500 }).catch(() => false)) {
      throw new Error('LinkedIn Post button is disabled in composer dialog.');
    }
  }

  const afterClickSuccess = await dismissPostSuccessModal(page);
  if (afterClickSuccess.published) {
    return afterClickSuccess.postUrl;
  }

  throw new Error('LinkedIn Post button not found in active composer dialog.');
}

export async function dismissPostSuccessModal(page) {
  const successScopes = [
    page.locator('[role="dialog"], [data-test-modal-container]').filter({ hasText: /post successful/i }),
    page.locator('[role="alert"], [data-test-artdeco-toast-item-type="success"]').filter({ hasText: /post successful/i }),
    page.locator('div').filter({ hasText: /^post successful\.?$/i }),
  ];

  let successDialog = null;
  for (const scope of successScopes) {
    const el = scope.first();
    if (await el.isVisible({ timeout: 2000 }).catch(() => false)) {
      successDialog = el;
      break;
    }
  }

  if (!successDialog) {
    return { published: false, postUrl: null };
  }

  let postUrl = null;
  const viewPostScopes = [
    successDialog.getByRole('link', { name: /view post/i }),
    page.getByRole('link', { name: /view post/i }),
  ];
  for (const viewPost of viewPostScopes) {
    if (await viewPost.first().isVisible({ timeout: 2000 }).catch(() => false)) {
      postUrl = await viewPost.first().getAttribute('href');
      if (postUrl) {
        break;
      }
    }
  }

  const dismissScopes = [
    successDialog.getByRole('button', { name: /not now|continue|dismiss|close|no thanks/i }),
    page.getByRole('button', { name: /^no thanks$/i }),
    page.getByRole('button', { name: /^close$/i }).filter({ has: page.locator('xpath=ancestor::*[contains(., "Post successful")]') }),
  ];
  for (const dismiss of dismissScopes) {
    if (await dismiss.first().isVisible({ timeout: 1500 }).catch(() => false)) {
      await dismiss.first().click();
      break;
    }
  }

  return { published: true, postUrl };
}

export async function waitForDialogClose(page) {
  const deadline = Date.now() + 90000;
  while (Date.now() < deadline) {
    const modal = await dismissPostSuccessModal(page);
    if (modal.published) {
      return modal.postUrl;
    }

    const sharebox = page.locator('[data-test-modal-id="sharebox"]');
    const shareboxVisible = await sharebox.isVisible({ timeout: 400 }).catch(() => false);
    const dialogVisible = await page.locator('[role="dialog"]').isVisible({ timeout: 300 }).catch(() => false);
    if (!shareboxVisible && !dialogVisible) {
      return null;
    }

    await page.waitForTimeout(1000);
  }

  const sharebox = page.locator('[data-test-modal-id="sharebox"]');
  if (await sharebox.isVisible({ timeout: 500 }).catch(() => false)) {
    throw new Error('LinkedIn composer dialog still open after clicking Post.');
  }

  const dialog = page.locator('[role="dialog"]');
  if (await dialog.isVisible({ timeout: 500 }).catch(() => false)) {
    throw new Error('LinkedIn composer dialog still open after clicking Post.');
  }

  return null;
}

export function linkedInUrlsMatch(current, target) {
  if (!current || !target) {
    return false;
  }
  try {
    const a = new URL(current, 'https://www.linkedin.com');
    const b = new URL(target, 'https://www.linkedin.com');
    if (a.origin !== b.origin) {
      return false;
    }
    const normalizePath = (url) => url.pathname.replace(/\/$/, '') || '/';
    if (normalizePath(a) !== normalizePath(b)) {
      return false;
    }
    const aActor = a.searchParams.get('actorCompanyId');
    const bActor = b.searchParams.get('actorCompanyId');
    if (aActor && bActor && aActor !== bActor) {
      return false;
    }
    return true;
  } catch {
    return false;
  }
}

function linkedInPostUrn(url) {
  return String(url || '').match(/urn:li:(?:share|activity):[^/?#]+/i)?.[0] || null;
}

function sameLinkedInPostPage(current, target) {
  const currentUrn = linkedInPostUrn(current);
  const targetUrn = linkedInPostUrn(target);
  return Boolean(currentUrn && targetUrn && currentUrn === targetUrn);
}

export function isDirectLinkedInPostUrl(url) {
  if (!url) {
    return false;
  }
  return /linkedin\.com\/(feed\/update|posts\/)/i.test(url)
    || /urn:li:(activity|share)/i.test(url);
}

/**
 * Resolve a captured View-post href to an absolute LinkedIn post URL.
 * @param {string|null|undefined} href
 * @param {string} [baseUrl]
 * @returns {string|null}
 */
export function canonicalizeLinkedInPostHref(href, baseUrl = 'https://www.linkedin.com/') {
  if (!href) {
    return null;
  }
  try {
    const absolute = new URL(String(href).trim(), baseUrl).toString().split('#')[0];
    if (isDirectLinkedInPostUrl(absolute)) {
      return absolute;
    }
  } catch {
    // ignore
  }
  return null;
}

/**
 * Resolve a raw primary post URL for repost validation (canonical absolute URL when possible).
 * @param {string|null|undefined} rawUrl
 * @returns {string|null}
 */
export function resolveLinkedInPrimaryPostUrl(rawUrl) {
  if (!rawUrl) {
    return null;
  }
  return canonicalizeLinkedInPostHref(rawUrl) || String(rawUrl).trim() || null;
}

function personalSlugFromProfileUrl(profileUrl) {
  return String(profileUrl || '').match(/\/in\/([^/?#]+)/i)?.[1] || null;
}

function extractLinkedInCompanyIdFromPageUrl(pageUrl) {
  const match = String(pageUrl || '').match(/\/(?:company|showcase)\/([^/?#]+)/i);
  return match?.[1] || null;
}

function normalizeLinkedInRepostPostUrl(postUrl, companyId = null) {
  try {
    const url = new URL(String(postUrl), 'https://www.linkedin.com');
    const resolvedCompanyId = url.searchParams.get('actorCompanyId')
      || companyId
      || extractLinkedInCompanyIdFromPageUrl(postUrl);
    if (resolvedCompanyId) {
      url.searchParams.set('actorCompanyId', String(resolvedCompanyId));
    }
    return url.toString();
  } catch {
    return String(postUrl || '')
      .replace(/([?&])actorCompanyId=[^&]*/gi, '$1')
      .replace(/\?&/, '?')
      .replace(/[?&]$/, '');
  }
}

/**
 * Bootstrap URL for LinkedIn repost (normalized permalink + actorCompanyId when applicable).
 * @param {string|null|undefined} primaryPostUrl
 * @param {string|null|undefined} primaryPageUrl
 * @param {string|null|undefined} fallbackUrl
 * @returns {string}
 */
export function linkedInRepostBootstrapUrl(primaryPostUrl, primaryPageUrl, fallbackUrl) {
  if (!primaryPostUrl) {
    return fallbackUrl || 'https://www.linkedin.com/feed/';
  }
  const resolved = resolveLinkedInPrimaryPostUrl(primaryPostUrl) || primaryPostUrl;
  const companyId = extractLinkedInCompanyIdFromPageUrl(primaryPageUrl);
  return normalizeLinkedInRepostPostUrl(resolved, companyId);
}

/**
 * Bootstrap URL for LinkedIn repost automation — personal member view (no company actor).
 * @param {string|null|undefined} primaryPostUrl
 * @param {string|null|undefined} fallbackUrl
 * @returns {string}
 */
export function linkedInRepostPersonalBootstrapUrl(primaryPostUrl, fallbackUrl) {
  if (!primaryPostUrl) {
    return fallbackUrl || 'https://www.linkedin.com/feed/';
  }
  const resolved = resolveLinkedInPrimaryPostUrl(primaryPostUrl) || primaryPostUrl;
  return linkedInPostUrlWithoutCompanyActor(resolved);
}

/**
 * Personal feed URL stored as external_post_url after a successful repost.
 * @param {{ personalContextUrl?: string, personal_context_url?: string }} input
 * @returns {string}
 */
export function linkedInRepostSuccessUrl(input = {}) {
  return input.personalContextUrl || input.personal_context_url || 'https://www.linkedin.com/feed/';
}

function primaryBrandPattern(input) {
  const explicitBrand = input?.primaryPageBrand || input?.primary_page_brand;
  if (explicitBrand) {
    return new RegExp(String(explicitBrand).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i');
  }
  const companyId = extractLinkedInCompanyIdFromPageUrl(input?.primaryPageUrl);
  if (companyId) {
    return new RegExp(companyId.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i');
  }
  return null;
}

function companyActorExcludePattern(brandPattern) {
  return brandPattern || /showcase|company page|limited|ventures|co\.tt/i;
}

function linkedInPostUrlWithoutCompanyActor(postUrl) {
  try {
    const url = new URL(String(postUrl), 'https://www.linkedin.com');
    url.searchParams.delete('actorCompanyId');
    return url.toString();
  } catch {
    return String(postUrl || '')
      .replace(/([?&])actorCompanyId=[^&]*&?/gi, (_, sep) => (sep === '?' ? '?' : ''))
      .replace(/\?&/, '?')
      .replace(/[?&]$/, '');
  }
}

function isPersonalLinkedInPostView(url) {
  try {
    return !new URL(String(url), 'https://www.linkedin.com').searchParams.has('actorCompanyId');
  } catch {
    return !/actorCompanyId=/i.test(String(url || ''));
  }
}

export { linkedInPostUrlWithoutCompanyActor, isPersonalLinkedInPostView };

async function readLoggedInMemberName(page) {
  const meLabel = page.locator('.global-nav__me-content img[alt], button[aria-label*="Me:" i]').first();
  if (!(await meLabel.isVisible({ timeout: 1500 }).catch(() => false))) {
    return null;
  }
  const alt = (await meLabel.getAttribute('alt').catch(() => '')) || '';
  const aria = (await meLabel.getAttribute('aria-label').catch(() => '')) || '';
  const fromAria = aria.match(/Me:\s*(.+)$/i)?.[1]?.trim();
  if (fromAria) {
    return fromAria;
  }
  if (alt && !/linkedin/i.test(alt)) {
    return alt.trim();
  }
  return null;
}

async function readLoggedInMemberProfileUrl(page) {
  const meLink = page.locator(
    '.global-nav__me-content a[href*="/in/"], a.global-nav__me[href*="/in/"], button[aria-label*="Me:" i]'
  ).first();
  if (!(await meLink.isVisible({ timeout: 1500 }).catch(() => false))) {
    return null;
  }
  const href = (await meLink.getAttribute('href').catch(() => '')) || '';
  if (href && /\/in\//i.test(href)) {
    try {
      return new URL(href, 'https://www.linkedin.com').toString().split(/[?#]/)[0];
    } catch {
      // ignore
    }
  }
  return null;
}

async function readProfileUrlFromSidebar(page) {
  const links = page.locator('aside a[href*="/in/"], .scaffold-layout__aside a[href*="/in/"]');
  const count = await links.count();
  for (let i = 0; i < Math.min(count, 12); i++) {
    const href = (await links.nth(i).getAttribute('href').catch(() => '')) || '';
    if (/\/in\/[^/?#]+/i.test(href)) {
      try {
        return new URL(href, 'https://www.linkedin.com').toString().split(/[?#]/)[0];
      } catch {
        // ignore
      }
    }
  }
  return null;
}

async function resolvePersonalProfileUrl(page, input = {}) {
  const explicit = input.personalProfileUrl || input.personal_profile_url;
  if (explicit && /\/in\//i.test(explicit)) {
    return explicit;
  }
  const fromPage = await readLoggedInMemberProfileUrl(page);
  if (fromPage) {
    return fromPage;
  }
  const fromSidebar = await readProfileUrlFromSidebar(page);
  if (fromSidebar) {
    return fromSidebar;
  }
  const target = input.targetPageUrl || '';
  if (/\/in\//i.test(target)) {
    return target;
  }
  return null;
}

async function resolvePersonalMemberName(page, input = {}, profileUrl = null) {
  const fromPage = await readLoggedInMemberName(page);
  if (fromPage) {
    return fromPage;
  }
  const fromInput = input.personalProfileName || input.personal_profile_name;
  if (fromInput) {
    return String(fromInput).trim();
  }
  const slug = personalSlugFromProfileUrl(profileUrl || input.personalProfileUrl || input.targetPageUrl);
  if (slug) {
    return slug.replace(/-/g, ' ');
  }
  return null;
}

function linkedInActorModal(page) {
  return page.locator('[role="dialog"], dialog[data-testid="dialog"]').filter({
    hasText: /comment, react, and repost as/i,
  }).first();
}

async function dismissOpenActorModal(page) {
  const modal = linkedInActorModal(page);
  if (!(await modal.isVisible({ timeout: 400 }).catch(() => false))) {
    return false;
  }

  await page.keyboard.press('Escape').catch(() => {});
  await page.waitForTimeout(300).catch(() => {});
  if (!(await modal.isVisible({ timeout: 400 }).catch(() => false))) {
    return true;
  }

  const cancel = modal.getByRole('button', { name: /^cancel$/i }).first();
  if (await cancel.isVisible({ timeout: 800 }).catch(() => false)) {
    await cancel.click({ timeout: 3000 }).catch(() => {});
    await page.waitForTimeout(300).catch(() => {});
  }

  return !(await modal.isVisible({ timeout: 400 }).catch(() => false));
}

async function clickPersonalActorOption(modal, memberName, brandPattern) {
  const excludePattern = companyActorExcludePattern(brandPattern);
  const firstName = memberName ? memberName.split(/\s+/)[0] : null;

  if (memberName) {
    const namedRadio = modal.getByRole('radio', {
      name: new RegExp(`^\\s*${memberName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\s*$`, 'i'),
    }).first();
    if (await namedRadio.isVisible({ timeout: 1500 }).catch(() => false)) {
      console.error(`[linkedin] Selecting actor option: ${memberName}`);
      await namedRadio.click({ force: true });
      return true;
    }
  }

  if (firstName) {
    const firstRadio = modal.getByRole('radio', { name: new RegExp(firstName, 'i') }).first();
    if (await firstRadio.isVisible({ timeout: 1500 }).catch(() => false)) {
      const label = ((await firstRadio.innerText().catch(() => '')) || '').trim();
      if (label && !excludePattern.test(label)) {
        console.error(`[linkedin] Selecting actor option: ${label.slice(0, 60)}`);
        await firstRadio.click({ force: true });
        return true;
      }
    }
  }

  const radios = modal.getByRole('radio');
  const count = await radios.count();
  for (let i = 0; i < count; i++) {
    const radio = radios.nth(i);
    const label = ((await radio.innerText().catch(() => '')) || '').trim();
    if (!label || excludePattern.test(label)) {
      continue;
    }
    console.error(`[linkedin] Selecting actor option: ${label.slice(0, 60)}`);
    await radio.click({ force: true });
    return true;
  }

  return false;
}

async function openPersonalActorModal(page) {
  if (await linkedInActorModal(page).isVisible({ timeout: 800 }).catch(() => false)) {
    return true;
  }

  const openers = [
    page.getByRole('link', { name: /switch to different account/i }).first(),
    page.getByRole('button', { name: /switch to different account/i }).first(),
    page.locator('[aria-label*="Comment as" i]').first(),
    page.locator('[placeholder*="Comment as" i]').first(),
    page.getByText(/^comment as /i).first(),
    page.getByRole('button', { name: /comment as/i }).first(),
  ];

  for (const opener of openers) {
    if (!(await opener.isVisible({ timeout: 1200 }).catch(() => false))) {
      continue;
    }
    const label = ((await opener.innerText().catch(() => '')) || '').trim();
    console.error(`[linkedin] Opening personal actor modal via ${label.slice(0, 40) || 'actor control'}`);
    await opener.click();
    await page.waitForTimeout(500).catch(() => {});
    if (await linkedInActorModal(page).isVisible({ timeout: 3000 }).catch(() => false)) {
      return true;
    }
  }

  return false;
}

async function selectPersonalActorInCommentModal(page, memberName = null, brandPattern = null) {
  const modal = linkedInActorModal(page);
  if (!(await modal.isVisible({ timeout: 1500 }).catch(() => false))) {
    return false;
  }

  console.error('[linkedin] Selecting personal actor in comment/repost modal');
  await clickPersonalActorOption(modal, memberName, brandPattern);

  await page.waitForTimeout(300).catch(() => {});
  const save = modal.getByRole('button', { name: /^save$/i }).first();
  if (await save.isEnabled({ timeout: 2000 }).catch(() => false)) {
    console.error('[linkedin] Saving personal actor selection');
    await save.click({ timeout: 5000 });
    await page.waitForTimeout(500).catch(() => {});
    return !(await modal.isVisible({ timeout: 800 }).catch(() => false));
  }

  console.error('[linkedin] Personal actor Save button not enabled after selection');
  await dismissOpenActorModal(page);
  return false;
}

async function dismissLinkedInBlockingDialogs(page) {
  await dismissOpenActorModal(page);
}

async function switchToPersonalActorViaAccountSwitcher(page, memberName = null, brandPattern = null) {
  if (!(await openPersonalActorModal(page))) {
    return false;
  }

  return selectPersonalActorInCommentModal(page, memberName, brandPattern);
}

function needsPersonalActorForRepost(page, input, profileUrl = null) {
  if (/actorCompanyId=/i.test(String(input?.primaryPostUrl || '')) || /actorCompanyId=/i.test(page.url())) {
    return true;
  }

  return false;
}

async function ensurePersonalActorForRepost(page, input, brandPattern = null) {
  const profileUrl = await resolvePersonalProfileUrl(page, input);
  const needsPersonal = needsPersonalActorForRepost(page, input, profileUrl)
    || await isLinkedInCompanyActorMode(page, personalSlugFromProfileUrl(profileUrl));
  if (!needsPersonal) {
    return profileUrl;
  }

  const memberName = await resolvePersonalMemberName(page, input, profileUrl);
  let switched = await switchToPersonalActorViaAccountSwitcher(page, memberName, brandPattern);
  if (!switched) {
    await page.locator('main, [role="main"]').first().scrollIntoViewIfNeeded().catch(() => {});
    switched = await switchToPersonalActorViaAccountSwitcher(page, memberName, brandPattern);
  }

  if (!switched) {
    throw new Error(
      `Could not switch LinkedIn actor to personal profile${memberName ? ` (${memberName})` : ''} for reposting.`
    );
  }

  return profileUrl;
}

async function verifyPersonalActorReady(page, input, brandPattern, profileUrl) {
  if (!/actorCompanyId=/i.test(page.url())) {
    return;
  }

  const excludePattern = companyActorExcludePattern(brandPattern);
  const label = (await readCommentAsLabel(page)).toLowerCase();
  if (!label.includes('comment as')) {
    throw new Error('LinkedIn repost requires personal profile actor but company page context is still active.');
  }
  if (excludePattern.test(label)) {
    throw new Error('LinkedIn repost requires personal profile actor but company page actor is still active.');
  }

  const memberName = await resolvePersonalMemberName(page, input, profileUrl);
  if (memberName) {
    const firstName = memberName.split(/\s+/)[0].toLowerCase();
    if (firstName && label.includes(firstName)) {
      return;
    }
  }
  if (/comment as you\b/i.test(label)) {
    return;
  }

  throw new Error('LinkedIn repost requires personal profile actor but company page actor is still active.');
}

async function removeCompanyRepostIfPresent(page, input, brandPattern, profileUrl) {
  if (!needsPersonalActorForRepost(page, input, profileUrl)) {
    return;
  }

  const control = await waitForRepostControl(page, 8000);
  if (control?.kind !== 'unrepost') {
    return;
  }

  console.error('[linkedin] Removing company-page repost before personal repost');
  await control.locator.scrollIntoViewIfNeeded();
  await control.locator.click();
  await page.waitForTimeout(1200).catch(() => {});
}

async function removeWrongCompanyRepostIfNeeded(page, companyPostUrl, input, brandPattern, profileUrl) {
  if (!needsPersonalActorForRepost(page, { primaryPostUrl: companyPostUrl }, profileUrl)) {
    return;
  }

  await page.goto(companyPostUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(1500).catch(() => {});
  await removeCompanyRepostIfPresent(page, { primaryPostUrl: companyPostUrl }, brandPattern, profileUrl);
}

async function readCommentAsLabel(page) {
  const fields = [
    page.locator('[aria-label*="Comment as" i]').first(),
    page.locator('[placeholder*="Comment as" i]').first(),
    page.getByText(/^comment as /i).first(),
  ];
  for (const field of fields) {
    if (!(await field.isVisible({ timeout: 800 }).catch(() => false))) {
      continue;
    }
    const aria = (await field.getAttribute('aria-label').catch(() => '')) || '';
    const placeholder = (await field.getAttribute('placeholder').catch(() => '')) || '';
    const text = (await field.innerText().catch(() => '')) || '';
    const combined = `${aria} ${placeholder} ${text}`.trim();
    if (combined) {
      return combined;
    }
  }
  return '';
}

async function isLinkedInCompanyActorMode(page, personalProfileUrl = null) {
  const label = (await readCommentAsLabel(page)).toLowerCase();
  if (!label.includes('comment as')) {
    return false;
  }
  if (/comment as you\b/i.test(label)) {
    return false;
  }
  const slug = personalSlugFromProfileUrl(personalProfileUrl);
  if (slug) {
    const firstName = slug.split('-')[0];
    if (label.includes(slug.replace(/-/g, ' ')) || (firstName && label.includes(firstName))) {
      return false;
    }
  }
  return true;
}

async function readRepostMenuText(page) {
  const menu = page.locator('[role="menu"], .artdeco-dropdown__content').first();
  if (!(await menu.isVisible({ timeout: 2000 }).catch(() => false))) {
    return '';
  }
  return (await menu.innerText().catch(() => '')) || '';
}

function isCompanyPageInstantRepostMenu(menuText, brandPattern = null) {
  const text = String(menuText || '');
  if (!/instantly bring/i.test(text)) {
    return false;
  }
  if (/instantly bring.*post to others['’]? feeds/i.test(text)) {
    return true;
  }
  const excludePattern = companyActorExcludePattern(brandPattern);
  return excludePattern.test(text);
}

async function submitRepostWithThoughtsComposer(page, memberName = null) {
  const dialog = page.locator('[role="dialog"], .share-box-v2, [data-test-modal]').filter({
    hasText: /what do you want to talk about|feed post/i,
  }).first();

  let composerOpen = await dialog.isVisible({ timeout: 2000 }).catch(() => false);
  if (!composerOpen) {
    for (let i = 0; i < 8; i++) {
      await page.waitForTimeout(500).catch(() => {});
      composerOpen = await dialog.isVisible({ timeout: 500 }).catch(() => false);
      if (composerOpen) {
        break;
      }
    }
  }

  const postBtn = composerOpen
    ? dialog.getByRole('button', { name: /^post$/i }).last()
    : page.getByRole('button', { name: /^post$/i }).last();

  if (!(await postBtn.isVisible({ timeout: 8000 }).catch(() => false))) {
    throw new Error('LinkedIn repost composer did not open.');
  }

  if (memberName && composerOpen) {
    const dialogText = ((await dialog.innerText().catch(() => '')) || '').toLowerCase();
    const firstName = memberName.split(/\s+/)[0].toLowerCase();
    if (firstName && !dialogText.includes(firstName) && !/post to anyone/i.test(dialogText)) {
      throw new Error(`LinkedIn repost composer is not open for personal profile (${memberName}).`);
    }
  }

  if (!(await postBtn.isEnabled({ timeout: 5000 }).catch(() => false))) {
    throw new Error('LinkedIn repost Post button is not enabled.');
  }

  console.error('[linkedin] Submitting personal repost via composer');
  await postBtn.click({ timeout: 5000 });
  await page.waitForTimeout(2000).catch(() => {});
}

async function verifyPersonalRepostPublished(page, input, snippet, profileUrl = null) {
  const hint = String(snippet || input.content || input.text || '').trim().slice(0, 40);
  if (!hint) {
    return;
  }

  const resolvedProfileUrl = profileUrl
    || input.personalProfileUrl
    || input.personal_profile_url
    || await resolvePersonalProfileUrl(page, input);
  if (!resolvedProfileUrl) {
    throw new Error('Could not resolve personal profile URL to verify LinkedIn repost.');
  }

  const activityUrl = `${resolvedProfileUrl.replace(/\/$/, '')}/recent-activity/all/`;
  await page.goto(activityUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(2000).catch(() => {});

  const deadline = Date.now() + 20000;
  while (Date.now() < deadline) {
    const body = ((await page.locator('main, [role="main"]').innerText().catch(() => '')) || '').toLowerCase();
    if (body.includes('reposted') && body.includes(hint.toLowerCase())) {
      console.error('[linkedin] Verified personal repost on member activity feed');
      return;
    }
    await page.waitForTimeout(1000).catch(() => {});
  }

  throw new Error('LinkedIn personal repost was not found on member activity feed.');
}

async function clickInstantRepost(page, brandPattern = null, memberName = null) {
  await page.waitForTimeout(500).catch(() => {});
  let menuText = await readRepostMenuText(page);
  for (let i = 0; i < 10 && !menuText; i++) {
    await page.waitForTimeout(300).catch(() => {});
    menuText = await readRepostMenuText(page);
  }

  const usePersonalComposer = Boolean(brandPattern)
    || isCompanyPageInstantRepostMenu(menuText, brandPattern);

  if (usePersonalComposer) {
    const options = [
      page.getByRole('menuitem', { name: /repost with your thoughts/i }).first(),
      page.getByRole('button', { name: /repost with your thoughts/i }).first(),
      page.locator('.artdeco-dropdown__content-inner').getByText(/^repost with your thoughts$/i).first(),
      page.locator('[role="menu"] *').filter({ hasText: /^repost with your thoughts$/i }).first(),
    ];
    for (const option of options) {
      if (!(await option.isVisible({ timeout: 2500 }).catch(() => false))) {
        continue;
      }
      console.error('[linkedin] Clicking repost menu option: Repost with your thoughts');
      await option.click({ force: true });
      await page.waitForTimeout(800).catch(() => {});
      await submitRepostWithThoughtsComposer(page, memberName);
      return;
    }
    throw new Error('LinkedIn personal repost menu option not found for company post.');
  }

  const menuOptions = [
    page.getByRole('menuitem', { name: /^repost$/i }).first(),
    page.getByRole('button', { name: /^repost$/i }).first(),
    page.locator('[role="menu"] *').filter({ hasText: /^repost$/i }).first(),
    page.getByRole('menuitem', { name: /instantly bring.*(?:your|followers|profile)/i }).first(),
    page.getByRole('button', { name: /instantly bring.*(?:your|followers|profile)/i }).first(),
    page.getByText(/instantly bring.*(?:your|followers|profile)/i).first(),
  ];

  for (const option of menuOptions) {
    if (!(await option.isVisible({ timeout: 2500 }).catch(() => false))) {
      continue;
    }
    const label = ((await option.innerText().catch(() => '')) || '').trim();
    if (!label || label.length < 4 || /^\d+$/.test(label)) {
      continue;
    }
    console.error(`[linkedin] Clicking repost menu option: ${label.slice(0, 60)}`);
    await option.click();
    await page.waitForTimeout(500).catch(() => {});

    const confirm = page.locator('[role="dialog"]').getByRole('button', { name: /^repost$/i }).last();
    if (await confirm.isVisible({ timeout: 1500 }).catch(() => false)) {
      console.error('[linkedin] Confirming repost in dialog');
      await confirm.click();
    }
    return;
  }

  throw new Error('LinkedIn repost menu action not found on primary post.');
}

async function findRepostControl(page) {
  const unrepost = page.locator('button[aria-label*="Unrepost" i], button[aria-label*="Remove repost" i]').first();
  if (await unrepost.isVisible({ timeout: 400 }).catch(() => false)) {
    return { kind: 'unrepost', locator: unrepost };
  }

  const repostBtn = page.getByRole('button', { name: /^repost$/i }).first();
  if (await repostBtn.isVisible({ timeout: 400 }).catch(() => false)) {
    return { kind: 'repost', locator: repostBtn };
  }

  const repostAria = page.locator('button[aria-label*="Repost" i]').first();
  if (await repostAria.isVisible({ timeout: 400 }).catch(() => false)) {
    return { kind: 'repost', locator: repostAria };
  }

  return null;
}

async function waitForRepostControl(page, timeoutMs = 20000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const control = await findRepostControl(page);
    if (control) {
      return control;
    }
    await page.waitForTimeout(500).catch(() => {});
  }

  return null;
}

async function assertPrimaryPostRepostReady(page, postUrl, options = {}) {
  const companyId = options.companyId ?? null;
  const normalized = normalizeLinkedInRepostPostUrl(postUrl, companyId);
  const onPostPage = linkedInUrlsMatch(page.url(), normalized) || sameLinkedInPostPage(page.url(), normalized);
  const skipGoto = options.skipGoto
    ?? (Boolean(options._bootstrapReady) && onPostPage);

  if (!skipGoto) {
    await page.goto(normalized, { waitUntil: 'domcontentloaded', timeout: options.timeout ?? 60000 });
    await page.waitForTimeout(1500).catch(() => {});
  } else if (!onPostPage) {
    await page.goto(normalized, { waitUntil: 'domcontentloaded', timeout: options.timeout ?? 60000 });
    await page.waitForTimeout(1500).catch(() => {});
  } else {
    await page.waitForTimeout(1000).catch(() => {});
  }

  if (!options._bootstrapReady) {
    await assertLinkedInSession(page);
  }

  await page.locator('main, [role="main"]').first().scrollIntoViewIfNeeded().catch(() => {});

  const waitMs = options.repostWaitMs ?? 20000;
  if (await waitForRepostControl(page, waitMs)) {
    return;
  }

  if (onPostPage || sameLinkedInPostPage(page.url(), normalized)) {
    console.error('[linkedin] Repost controls missing on post page; reloading once');
    await page.reload({ waitUntil: 'domcontentloaded', timeout: options.timeout ?? 60000 }).catch(() => {});
    await page.waitForTimeout(1500).catch(() => {});
    if (await waitForRepostControl(page, 15000)) {
      return;
    }
  }

  throw new Error('Repost button not found on primary LinkedIn post');
}

export async function repostLinkedInPost(page, input) {
  const brandPattern = primaryBrandPattern(input);
  await dismissLinkedInBlockingDialogs(page);

  const companyId = extractLinkedInCompanyIdFromPageUrl(input.primaryPageUrl);
  const repostReadyOptions = {
    companyId,
    _bootstrapReady: Boolean(input._bootstrapReady),
  };

  if (input.dryRun) {
    const rawPostUrl = input.primaryPostUrl;
    if (!rawPostUrl) {
      throw new Error('primaryPostUrl is required for LinkedIn repost dry-run');
    }
    if (!input.targetPageUrl && !input.personalContextUrl) {
      throw new Error('targetPageUrl is required for LinkedIn repost dry-run');
    }
    await assertPrimaryPostRepostReady(
      page,
      linkedInPostUrlWithoutCompanyActor(rawPostUrl),
      { ...repostReadyOptions, companyId: null }
    );
    return { verified: true, startUrl: page.url() };
  }

  const postUrl = resolveLinkedInPrimaryPostUrl(input.primaryPostUrl);
  if (!postUrl || !isDirectLinkedInPostUrl(postUrl)) {
    throw new Error('Primary LinkedIn post URL is required for reposting.');
  }

  if (!input.targetPageUrl && !input.personalContextUrl) {
    throw new Error('Target LinkedIn profile URL is required for reposting.');
  }

  const normalizedPostUrl = normalizeLinkedInRepostPostUrl(postUrl, companyId);
  const personalPostUrl = linkedInPostUrlWithoutCompanyActor(normalizedPostUrl);
  const successUrl = linkedInRepostSuccessUrl(input);

  await assertPrimaryPostRepostReady(page, personalPostUrl, {
    ...repostReadyOptions,
    companyId: null,
  });

  let profileUrl = await resolvePersonalProfileUrl(page, input);
  if (!profileUrl) {
    throw new Error('Could not resolve personal LinkedIn profile URL for reposting.');
  }
  const controlOnPersonal = await findRepostControl(page);
  if (controlOnPersonal?.kind === 'unrepost') {
    console.error('[linkedin] Primary post already reposted in personal profile context');
    return { postUrl: successUrl, verified: true };
  }

  await removeWrongCompanyRepostIfNeeded(page, normalizedPostUrl, input, brandPattern, profileUrl);
  profileUrl = await resolvePersonalProfileUrl(page, input);

  if (!isPersonalLinkedInPostView(page.url())) {
    console.error('[linkedin] Returning to personal post view after company cleanup');
    await assertPrimaryPostRepostReady(page, personalPostUrl, {
      companyId: null,
      skipGoto: false,
      _bootstrapReady: false,
    });
    profileUrl = await resolvePersonalProfileUrl(page, input);
  }

  if (await isLinkedInCompanyActorMode(page, personalSlugFromProfileUrl(profileUrl))) {
    profileUrl = await ensurePersonalActorForRepost(page, input, brandPattern);
    if (!isPersonalLinkedInPostView(page.url())) {
      await assertPrimaryPostRepostReady(page, personalPostUrl, {
        companyId: null,
        skipGoto: false,
        _bootstrapReady: false,
      });
    }
  }

  await dismissOpenActorModal(page);

  const control = await waitForRepostControl(page, 15000);
  if (!control) {
    throw new Error('Repost button not found on primary LinkedIn post.');
  }
  if (control.kind === 'unrepost') {
    console.error('[linkedin] Primary post already reposted in personal profile context');
    return { postUrl: successUrl, verified: true };
  }

  await control.locator.scrollIntoViewIfNeeded();
  await control.locator.click();
  const memberName = await resolvePersonalMemberName(page, input, profileUrl);
  await clickInstantRepost(page, brandPattern, memberName);
  await page.waitForTimeout(800).catch(() => {});
  await verifyPersonalRepostPublished(page, input, null, profileUrl);

  return { postUrl: successUrl, verified: true };
}
