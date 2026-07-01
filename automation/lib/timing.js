/** Per-character delay for pressSequentially (0 = instant). */
export const TYPE_DELAY_MS = 0;

export async function sleepMs(ms) {
  if (ms > 0) {
    await new Promise((resolve) => setTimeout(resolve, ms));
  }
}

/**
 * Race a promise against an internal wall-clock budget, throwing a clear error
 * if the budget is exceeded instead of silently running until the host
 * process's own hard timeout kills it with no diagnostics.
 *
 * Always clears/unrefs the timer so a fast-resolving `work` promise doesn't
 * leave a dangling timer keeping the process (or a test runner) alive for the
 * remainder of `timeoutMs`.
 *
 * @template T
 * @param {Promise<T>} work
 * @param {number} timeoutMs
 * @param {string} message
 * @returns {Promise<T>}
 */
export async function raceWithTimeout(work, timeoutMs, message) {
  let timer;
  const timeout = new Promise((_, reject) => {
    timer = setTimeout(() => reject(new Error(message)), timeoutMs);
    timer.unref?.();
  });

  try {
    return await Promise.race([work, timeout]);
  } finally {
    clearTimeout(timer);
  }
}
