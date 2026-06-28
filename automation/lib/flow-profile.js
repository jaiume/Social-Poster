export function actionFromRole(role) {
  if (role === 'repost') {
    return 'repost';
  }
  return 'post';
}

export function flowProfileFromAction(action) {
  return action === 'repost' ? 'repost' : 'primary_post';
}
