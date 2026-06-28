/** Per-character delay for pressSequentially (0 = instant). */
export const TYPE_DELAY_MS = 0;

export async function sleepMs(ms) {
  if (ms > 0) {
    await new Promise((resolve) => setTimeout(resolve, ms));
  }
}
