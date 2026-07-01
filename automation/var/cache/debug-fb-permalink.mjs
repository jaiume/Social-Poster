import { launchBrowser, closeBrowserSafe, readStdinJson } from '../../lib/session.js';
import { resolveFacebookPrimaryPostPermalink } from '../../lib/facebook.js';

const input = await readStdinJson();
const pageUrl = input.pageUrl;
const textHint = input.textHint || '';
const { browser, context, page } = await launchBrowser({ ...input, platform: 'facebook' });

try {
  const resolved = await resolveFacebookPrimaryPostPermalink(page, pageUrl, { textHint });
  console.error('resolved permalink:', resolved);
  if (input.screenshotPath) {
    await page.screenshot({ path: input.screenshotPath, fullPage: true }).catch(() => {});
  }
  console.log(JSON.stringify({ success: true, resolved }));
} catch (e) {
  console.error('ERROR:', e.message);
  if (input.screenshotPath) {
    await page.screenshot({ path: input.screenshotPath, fullPage: true }).catch(() => {});
  }
  console.log(JSON.stringify({ success: false, error: e.message }));
} finally {
  await closeBrowserSafe(browser, context);
}
