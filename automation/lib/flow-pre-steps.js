/** Host pre_steps keyed by platform-action and account kind. */
const PRE_STEPS_BY_FLOW = {
  'facebook-post-sub': ['assert_session', 'abort_if_unavailable'],
  'facebook-post-root': ['assert_session', 'ensure_personal_profile', 'abort_if_unavailable'],
  'linkedin-post-root': ['assert_session'],
  'linkedin-post-sub': ['assert_session'],
  'facebook-repost': ['assert_session', 'ensure_personal_profile', 'abort_if_unavailable'],
  'linkedin-repost': ['assert_session'],
};

/**
 * @param {string} platform
 * @param {string} action
 * @param {string} [accountKind]
 * @returns {string[]}
 */
export function getSuggestedPreSteps(platform, action, accountKind = 'sub') {
  if (platform === 'facebook' && action === 'post') {
    const key = accountKind === 'root' ? 'facebook-post-root' : 'facebook-post-sub';
    return PRE_STEPS_BY_FLOW[key] ?? [];
  }
  if (platform === 'facebook' && action === 'resolvePrimary') {
    const key = accountKind === 'root' ? 'facebook-post-root' : 'facebook-post-sub';
    return PRE_STEPS_BY_FLOW[key] ?? [];
  }
  if (platform === 'linkedin' && (action === 'post' || action === 'resolvePrimary')) {
    const key = accountKind === 'root' ? 'linkedin-post-root' : 'linkedin-post-sub';
    return PRE_STEPS_BY_FLOW[key] ?? [];
  }
  return PRE_STEPS_BY_FLOW[`${platform}-${action}`] ?? [];
}
