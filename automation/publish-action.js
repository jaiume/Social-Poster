#!/usr/bin/env node
import { launchBrowser, readStdinJson, respond, saveSessionState, closeBrowserSafe } from './lib/session.js';
import { screenshot } from './lib/facebook.js';
import { linkedInRepostPersonalBootstrapUrl } from './lib/linkedin.js';
import { bootstrapHostPage } from './lib/host-bootstrap.js';
import { dispatchPosterAction } from './lib/poster/index.js';
import { actionFromRole } from './lib/flow-profile.js';

const input = await readStdinJson();
let browser;
let context;
let page;

function classifyError(message = '') {
  const text = String(message).toLowerCase();
  if (text.includes('captcha') || text.includes('security challenge')) return ['AUTH_REQUIRED', 'auth', false];
  if (text.includes('not found') || text.includes('could not')) return ['SELECTOR_NOT_FOUND', 'ui', true];
  if (text.includes('timed out')) return ['AUTOMATION_ERROR', 'timeout', true];
  if (text.includes('session expired') || text.includes('re-capture')) return ['SESSION_EXPIRED', 'session', false];
  if (text.includes('login')) return ['SESSION_EXPIRED', 'session', false];
  return ['AUTOMATION_ERROR', 'unknown', true];
}

function resolveAction(raw) {
  if (raw && typeof raw === 'string' && raw.includes('.')) {
    return raw;
  }
  const platform = input.platform;
  const action = input.action ?? actionFromRole(input.targetRole ?? 'primary');
  if (!platform) {
    throw new Error('action or platform is required');
  }
  return `${platform}.${action}`;
}

function platformFromAction(action) {
  return String(action).split('.')[0];
}

try {
  const action = resolveAction(input.action ?? input.posterAction);
  const platform = platformFromAction(action);

  ({ browser, context, page } = await launchBrowser({ ...input, platform }));
  const posterInput = {
    text: input.text ?? input.content ?? '',
    content: input.content ?? input.text ?? '',
    imagePath: input.imagePath ?? input.image_path ?? null,
    pageUrl: input.pageUrl || input.operatorStartUrl || input.targetPageUrl,
    operatorStartUrl: input.operatorStartUrl,
    targetPageUrl: input.targetPageUrl || input.pageUrl,
    personalContextUrl: input.personalContextUrl ?? input.personal_context_url ?? null,
    accountKind: input.accountKind ?? input.account_kind ?? 'sub',
    subPageId: input.subPageId ?? input.sub_page_id ?? null,
    primaryPostUrl: input.primaryPostUrl ?? input.primary_post_url ?? null,
    primaryPageUrl: input.primaryPageUrl ?? input.primary_page_url ?? null,
    primaryPageBrand: input.primaryPageBrand ?? input.primary_page_brand ?? null,
    dryRun: Boolean(input.dryRun),
    input,
  };

  const repostFallback = posterInput.personalContextUrl || posterInput.pageUrl;
  const bootstrapUrl = action.split('.')[1] === 'repost'
    ? (platform === 'linkedin'
      ? linkedInRepostPersonalBootstrapUrl(posterInput.primaryPostUrl, repostFallback)
      : (posterInput.primaryPostUrl || repostFallback))
    : posterInput.pageUrl;

  await bootstrapHostPage(page, {
    ...input,
    platform,
    action: action.split('.')[1] ?? 'post',
    accountKind: posterInput.accountKind,
    pageUrl: bootstrapUrl,
    primaryPageBrand: posterInput.primaryPageBrand,
  });

  const result = await dispatchPosterAction(action, page, posterInput);

  if (input.sessionPath && context) {
    await saveSessionState(context, input.sessionPath).catch(() => {});
  }

  respond({
    success: true,
    action,
    postUrl: result.postUrl ?? null,
    operatorTargetUrl: input.pageUrl ?? null,
    resolvedStartUrl: result.resolvedStartUrl ?? result.startUrl ?? page.url(),
    verified: result.verified ?? true,
    resolverReasonCode: 'POSTER_ACTION',
    resolverConfidence: 'strong',
  });
} catch (e) {
  const screenshotPath = page ? await screenshot(page, input, 'poster-fail').catch(() => null) : null;
  const [errorCode, errorClass, retryable] = classifyError(e.message);
  const sessionInvalid = errorCode === 'SESSION_EXPIRED' || errorCode === 'AUTH_REQUIRED';
  respond({
    success: false,
    error: e.message,
    errorCode,
    errorClass,
    retryable,
    sessionInvalid,
    screenshotPath,
  });
  process.exit(1);
} finally {
  await closeBrowserSafe(browser, context);
}
