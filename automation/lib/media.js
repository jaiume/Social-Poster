import fs from 'fs';
/**
 * @returns {Promise<boolean>} true when a file was attached via an input or file chooser
 */
export async function attachImage(page, imagePath, scope = null) {
  if (!imagePath) {
    return false;
  }
  if (!fs.existsSync(imagePath)) {
    throw new Error(`Image file not found: ${imagePath}`);
  }

  const root = scope ?? page;

  const fileInputs = root.locator('input[type="file"]');
  const inputCount = await fileInputs.count();
  for (let i = 0; i < inputCount; i++) {
    try {
      await fileInputs.nth(i).setInputFiles(imagePath);
      return true;
    } catch {
      // try next file input
    }
  }

  const pageFileInputs = page.locator('input[type="file"]');
  const pageInputCount = await pageFileInputs.count();
  for (let i = 0; i < pageInputCount; i++) {
    try {
      await pageFileInputs.nth(i).setInputFiles(imagePath);
      return true;
    } catch {
      // try next file input
    }
  }

  const mediaButtons = [
    root.getByRole('button', { name: /^photo\/video$/i }),
    root.locator('[aria-label*="Photo/video" i]'),
    root.getByRole('button', { name: /photo|image|media|add photo|upload/i }),
    root.locator('[aria-label*="photo" i]'),
    root.locator('[aria-label*="image" i]'),
  ];

  for (const btn of mediaButtons) {
    const el = btn.first();
    if (!(await el.isVisible({ timeout: 1500 }).catch(() => false))) {
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

  return false;
}
