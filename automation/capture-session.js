#!/usr/bin/env node
/**
 * Interactive session capture for admin popup UI.
 * Usage: node capture-session.js <workDir> <platform>
 *
 * Reads commands from workDir/command.json, writes status.json and screenshot.png.
 * On successful login, waits for the user to click Save session before writing storage.json.
 */
import fs from 'fs';
import path from 'path';
import {
  launchBrowser,
} from './lib/session.js';

const workDir = process.argv[2];
const platform = process.argv[3];

if (!workDir || !platform) {
  console.error('Usage: node capture-session.js <workDir> <platform>');
  process.exit(1);
}

const LOGIN_URLS = {
  facebook: 'https://www.facebook.com/login',
  linkedin: 'https://www.linkedin.com/login',
};

function writeStatus(status, message = '', extra = {}) {
  const payload = {
    status,
    message,
    platform,
    updatedAt: new Date().toISOString(),
    ...extra,
  };
  fs.writeFileSync(path.join(workDir, 'status.json'), JSON.stringify(payload));
}

function readCommand() {
  const commandPath = path.join(workDir, 'command.json');
  if (!fs.existsSync(commandPath)) {
    return null;
  }
  try {
    const cmd = JSON.parse(fs.readFileSync(commandPath, 'utf8'));
    fs.unlinkSync(commandPath);
    return cmd;
  } catch {
    fs.unlinkSync(commandPath);
    return null;
  }
}


async function facebookLoginPhase(page, context) {
  const cookies = await context.cookies();
  if (!cookies.some((c) => c.name === 'c_user' && c.value)) {
    return 'login';
  }

  const otp = page.locator(
    'input[autocomplete="one-time-code"], input[name="approvals_code"], input#approvals_code'
  );
  if (await otp.first().isVisible({ timeout: 400 }).catch(() => false)) {
    return '2fa';
  }

  const verifyHeading = page.getByText(/authentication code|two-factor|2-factor|approvals code|enter login code|authenticator/i);
  if (await verifyHeading.first().isVisible({ timeout: 400 }).catch(() => false)) {
    return '2fa';
  }

  const url = page.url();
  if (/two_step_verification|approvals_code/i.test(url)) {
    return '2fa';
  }
  if (/\/login|checkpoint/i.test(url)) {
    return 'checkpoint';
  }

  const continueBtn = page.locator('[aria-label^="Continue " i]').first();
  if (await continueBtn.isVisible({ timeout: 400 }).catch(() => false)) {
    const body = ((await page.locator('body').innerText().catch(() => '')) || '').toLowerCase();
    if (!/what'?s on your mind/i.test(body)) {
      return 'profile_picker';
    }
  }

  const body = ((await page.locator('body').innerText().catch(() => '')) || '').toLowerCase();
  if (/use another profile|create new account|explore the things you love/i.test(body)) {
    return 'profile_picker';
  }

  const loginEmail = page.locator('input[name="email"], #email');
  if (await loginEmail.isVisible({ timeout: 400 }).catch(() => false)) {
    return 'login';
  }

  return 'ready';
}

async function isFacebookReady(page, context) {
  const phase = await facebookLoginPhase(page, context);
  if (phase !== 'ready') {
    return false;
  }

  if (!page.getByText(/what'?s on your mind/i).first().isVisible({ timeout: 800 }).catch(() => false)) {
    await page.goto('https://www.facebook.com/', {
      waitUntil: 'domcontentloaded',
      timeout: 60000,
    }).catch(() => {});
    await page.waitForLoadState('networkidle', { timeout: 12000 }).catch(() => {});
  }

  return (await facebookLoginPhase(page, context)) === 'ready';
}

async function linkedInLoginPhase(page, context) {
  const cookies = await context.cookies();
  if (!cookies.some((c) => c.name === 'li_at' && c.value)) {
    return 'login';
  }

  const url = page.url();
  if (/\/login|checkpoint|authwall|challenge/i.test(url)) {
    return 'checkpoint';
  }

  const otp = page.locator(
    'input[autocomplete="one-time-code"], input[name="pin"], input#input__email_verification_pin, input#input__phone_verification_pin'
  );
  if (await otp.first().isVisible({ timeout: 400 }).catch(() => false)) {
    return '2fa';
  }

  const verifyHeading = page.getByText(/verify|authentication|security code|authenticator/i);
  if (await verifyHeading.first().isVisible({ timeout: 400 }).catch(() => false)) {
    return '2fa';
  }

  return 'ready';
}

async function isLinkedInReady(page, context) {
  const phase = await linkedInLoginPhase(page, context);
  if (phase !== 'ready') {
    return false;
  }

  if (!/\/feed|\/mynetwork|\/in\/|\/company\/|\/showcase\//i.test(page.url())) {
    await page.goto('https://www.linkedin.com/feed/', {
      waitUntil: 'domcontentloaded',
      timeout: 60000,
    }).catch(() => {});
    await page.waitForLoadState('networkidle', { timeout: 12000 }).catch(() => {});
  }

  return (await linkedInLoginPhase(page, context)) === 'ready';
}

async function isLoggedIn(page, context, plat) {
  if (plat === 'facebook') {
    return isFacebookReady(page, context);
  }
  if (plat === 'linkedin') {
    return isLinkedInReady(page, context);
  }
  return false;
}

function linkedInStatusMessage(phase) {
  if (phase === '2fa') {
    return 'Complete LinkedIn two-factor authentication (2FA), then wait for confirmation.';
  }
  if (phase === 'checkpoint') {
    return 'Complete LinkedIn security verification, then wait for confirmation.';
  }
  return 'Log in using the view below. Click fields and type as normal.';
}

function facebookStatusMessage(phase) {
  if (phase === '2fa') {
    return 'Enter your authenticator code when prompted, then wait until your Facebook home feed loads.';
  }
  if (phase === 'checkpoint') {
    return 'Complete Facebook security verification, then wait for confirmation.';
  }
  if (phase === 'profile_picker') {
    return 'Click your personal profile (Continue), then open Feeds before saving.';
  }
  return 'Log in using the view below. Click fields and type as normal.';
}

function readyToSaveMessage(plat) {
  if (plat === 'facebook') {
    return 'Logged in. Open Feeds (your personal home — not EntryZen page admin), then click Save session below.';
  }
  return 'Logged in. Open the feed you want saved, then click Save session below.';
}

async function saveAndExit(context, workDir) {
  const storageState = await context.storageState();
  fs.writeFileSync(path.join(workDir, 'storage.json'), JSON.stringify(storageState));
  writeStatus('complete', 'Login captured successfully.');
}

async function runCommand(page, context, workDir, cmd) {
  if (cmd.action === 'save') {
    await saveAndExit(context, workDir);
    return 'exit';
  }

  switch (cmd.action) {
    case 'click': {
      const x = Number(cmd.x);
      const y = Number(cmd.y);
      if (!Number.isFinite(x) || !Number.isFinite(y)) {
        break;
      }
      await page.mouse.click(x, y);
      await page.waitForLoadState('domcontentloaded', { timeout: 10000 }).catch(() => {});
      break;
    }
    case 'type':
      if (typeof cmd.text === 'string' && cmd.text !== '') {
        await page.keyboard.type(cmd.text, { delay: 40 });
      }
      break;
    case 'key':
      if (typeof cmd.key === 'string' && cmd.key !== '') {
        await page.keyboard.press(cmd.key);
        if (cmd.key === 'Enter') {
          await page.waitForLoadState('domcontentloaded', { timeout: 10000 }).catch(() => {});
        }
      }
      break;
    default:
      break;
  }

  return null;
}

async function takeScreenshot(page, workDir) {
  const screenshotPath = path.join(workDir, 'screenshot.jpg');
  await page.screenshot({
    path: screenshotPath,
    type: 'jpeg',
    quality: 80,
    timeout: 15000,
    animations: 'disabled',
    caret: 'hide',
  });
}

async function main() {
  fs.mkdirSync(workDir, { recursive: true });
  writeStatus('starting', 'Launching browser…');

  let browser;
  let context;
  let page;
  try {
    ({ browser, context, page } = await launchBrowser({ headless: true }));
    page.setDefaultTimeout(30000);

    const loginUrl = LOGIN_URLS[platform] || LOGIN_URLS.facebook;
    await page.goto(loginUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
    writeStatus('running', 'Log in using the view below. Click fields and type as normal.');

    const started = Date.now();
    const maxMs = 15 * 60 * 1000;
    let screenshotFailures = 0;
    let awaitingSave = false;

    while (Date.now() - started < maxMs) {
      const cmd = readCommand();
      if (cmd) {
        const outcome = await runCommand(page, context, workDir, cmd);
        if (outcome === 'exit') {
          await browser.close();
          process.exit(0);
        }
      }

      try {
        await takeScreenshot(page, workDir);
        screenshotFailures = 0;
      } catch (screenshotError) {
        screenshotFailures += 1;
        if (screenshotFailures >= 30) {
          throw screenshotError;
        }
      }

      if (!awaitingSave) {
        if (platform === 'linkedin') {
          const phase = await linkedInLoginPhase(page, context);
          if (phase !== 'ready') {
            writeStatus('running', linkedInStatusMessage(phase));
          }
        }

        if (platform === 'facebook') {
          const phase = await facebookLoginPhase(page, context);
          if (phase !== 'ready') {
            writeStatus('running', facebookStatusMessage(phase));
          }
        }

        if (await isLoggedIn(page, context, platform)) {
          awaitingSave = true;
          writeStatus('ready', readyToSaveMessage(platform));
        }
      }

      await new Promise((r) => setTimeout(r, 400));
    }

    writeStatus('error', awaitingSave
      ? 'Timed out waiting for Save session (15 minutes).'
      : 'Timed out waiting for login (15 minutes).');
    await browser.close();
    process.exit(1);
  } catch (e) {
    writeStatus('error', e.message || String(e));
    if (browser) {
      await browser.close();
    }
    process.exit(1);
  }
}

main();
