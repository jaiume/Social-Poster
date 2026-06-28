/**
 * Resolve playbook locator specs (Playwright API shape) to Playwright locators.
 * @param {import('playwright').Page} page
 * @param {import('playwright').Locator} [scope]
 */
function regexPattern(value, exact = false) {
  if (value == null || value === '') {
    return undefined;
  }
  return new RegExp(String(value), exact ? '' : 'i');
}

export function resolveLocator(page, spec, scope = null) {
  if (!spec || typeof spec !== 'object' || Array.isArray(spec)) {
    return null;
  }
  if (spec.kind !== undefined) {
    return null;
  }
  const root = scope ?? page;
  const pw = spec.pw;
  if (pw === 'getByRole') {
    if (typeof spec.role !== 'string') {
      return null;
    }
    const opts = {};
    const name = regexPattern(spec.name, spec.exact);
    if (name) {
      opts.name = name;
    }
    if (spec.exact) {
      opts.exact = true;
    }
    return root.getByRole(spec.role, opts);
  }
  if (pw === 'getByText') {
    const text = spec.text ?? spec.name;
    if (text == null || text === '') {
      return null;
    }
    const opts = {};
    const pattern = regexPattern(text, spec.exact);
    if (spec.exact) {
      opts.exact = true;
    }
    return root.getByText(pattern, opts);
  }
  if (pw === 'getByLabel') {
    const label = spec.label ?? spec.name;
    if (label == null || label === '') {
      return null;
    }
    const opts = {};
    const pattern = regexPattern(label, spec.exact);
    if (spec.exact) {
      opts.exact = true;
    }
    return root.getByLabel(pattern, opts);
  }
  if (pw === 'locator') {
    if (typeof spec.selector !== 'string' || spec.selector === '') {
      return null;
    }
    let loc = root.locator(spec.selector);
    const filter = spec.filter;
    if (filter && typeof filter === 'object' && filter.hasText) {
      loc = loc.filter({ hasText: regexPattern(filter.hasText) });
    }
    return loc;
  }
  return null;
}

export async function clickLocator(page, spec, scope = null) {
  const el = resolveLocator(page, spec, scope);
  if (!el) {
    throw new Error('Could not resolve locator spec');
  }
  await el.first().scrollIntoViewIfNeeded();
  await el.first().click({ timeout: 15000 });
}

export async function findVisibleLocator(page, spec, scope = null, timeoutMs = 5000) {
  const el = resolveLocator(page, spec, scope);
  if (!el) {
    return null;
  }
  const first = el.first();
  if (await first.isVisible({ timeout: timeoutMs }).catch(() => false)) {
    return first;
  }
  return null;
}

export async function fillLocator(locator, content) {
  await locator.click({ timeout: 10000 });
  await locator.fill(content, { timeout: 15000 });
}

export async function attachImageViaLocator(page, spec, scope, imagePath) {
  if (!spec || !imagePath) {
    return false;
  }
  const el = resolveLocator(page, spec, scope);
  if (!el) {
    throw new Error('Could not resolve attach_image locator');
  }
  const [fileChooser] = await Promise.all([
    page.waitForEvent('filechooser', { timeout: 15000 }),
    el.first().click({ timeout: 15000 }),
  ]);
  await fileChooser.setFiles(imagePath);
  return true;
}

function formatLocatorSpec(spec) {
  if (!spec || typeof spec !== 'object') {
    return '{}';
  }
  return JSON.stringify(spec);
}

/**
 * Test whether a locator resolves and is visible (discover agent tool).
 * @param {import('playwright').Page} page
 * @param {object} spec
 * @param {'page'|'dialog'} [scopeKind]
 * @param {string} [label]
 */
export async function probeLocator(page, spec, scopeKind = 'page', label = '') {
  const prefix = label ? `${label}: ` : '';
  if (!spec || typeof spec !== 'object') {
    return `${prefix}invalid locator spec`;
  }
  if (spec.kind !== undefined) {
    return `${prefix}legacy kind locator is not supported; use pw specs (schema_version 2)`;
  }

  let scope = null;
  if (scopeKind === 'dialog') {
    const dialog = page.locator('[role="dialog"]').first();
    const dialogVisible = await dialog.isVisible({ timeout: 1500 }).catch(() => false);
    if (!dialogVisible) {
      return `${prefix}no visible dialog on page (scope=dialog). Locator: ${formatLocatorSpec(spec)}`;
    }
    scope = dialog;
  }

  const el = resolveLocator(page, spec, scope);
  if (!el) {
    return `${prefix}could not resolve locator. Spec: ${formatLocatorSpec(spec)}`;
  }

  const count = await el.count().catch(() => 0);
  if (count === 0) {
    return `${prefix}0 matches. Locator: ${formatLocatorSpec(spec)}`;
  }

  const first = el.first();
  const visible = await first.isVisible({ timeout: 2500 }).catch(() => false);
  if (!visible) {
    return `${prefix}${count} match(es) but none visible. Locator: ${formatLocatorSpec(spec)}`;
  }

  let sampleText = '';
  try {
    sampleText = (await first.innerText({ timeout: 2000 })).trim().replace(/\s+/g, ' ');
  } catch {
    sampleText = '';
  }

  const textPart = sampleText ? `, sample text="${sampleText}"` : '';
  return `${prefix}1 visible match (${count} total${textPart}). Locator: ${formatLocatorSpec(spec)}`;
}
