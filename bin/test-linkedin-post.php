#!/usr/bin/env php
<?php

declare(strict_types=1);

define('BASE_DIR', dirname(__DIR__));

require BASE_DIR . '/vendor/autoload.php';

use App\DAO\PostDao;
use App\DAO\ProfilePostingAccountDao;
use App\DAO\SessionAccountDao;
use App\Services\ImageGenerationService;
use App\Services\PosterAutomationService;
use App\Services\ProfileAccountService;

$postId = (int) ($argv[1] ?? 0);
$dryRun = in_array('--dry-run', $argv, true);
if ($postId <= 0) {
    fwrite(STDERR, "Usage: php bin/test-linkedin-post.php <post_id> [--dry-run]\n");
    exit(1);
}

$container = require BASE_DIR . '/config/container.php';
$postDao = $container->get(PostDao::class);
$postingDao = $container->get(ProfilePostingAccountDao::class);
$accountDao = $container->get(SessionAccountDao::class);
$profileAccounts = $container->get(ProfileAccountService::class);
$poster = $container->get(PosterAutomationService::class);

$post = $postDao->findById($postId);
if ($post === null) {
    fwrite(STDERR, "Post {$postId} not found.\n");
    exit(1);
}

$slot = $postingDao->findForProfilePlatform((int) $post['product_profile_id'], 'linkedin');
if ($slot === null) {
    fwrite(STDERR, "No LinkedIn posting account for post {$postId}.\n");
    exit(1);
}

$account = $profileAccounts->enrichAccount($accountDao->findById((int) $slot['session_account_id']) ?? []);
if (empty($account['id'])) {
    fwrite(STDERR, "Posting account not found.\n");
    exit(1);
}

$content = trim((string) ($post['content_linkedin'] ?? $post['content_facebook'] ?? ''));
if ($content === '') {
    fwrite(STDERR, "Post {$postId} has no content.\n");
    exit(1);
}

$imagePath = ImageGenerationService::resolveAbsolutePath($post);
$payload = [
    'text' => $content,
    'pageUrl' => (string) $account['bootstrap_url'],
    'operatorStartUrl' => (string) $account['bootstrap_url'],
    'accountKind' => (string) ($account['account_kind'] ?? 'sub'),
    'subPageId' => $account['sub_page_id'] ?? null,
    'dryRun' => $dryRun,
];
if ($imagePath !== null) {
    $payload['imagePath'] = $imagePath;
}

echo ($dryRun ? 'Dry-run' : 'Live') . " LinkedIn post for post {$postId}\n";
echo "Account: {$account['display_name']} ({$account['account_kind']})\n";
echo "URL: {$account['bootstrap_url']}\n";
if ($imagePath !== null) {
    echo "Image: {$imagePath}\n";
}

$sessionId = (int) ($account['browser_session_id'] ?? 0);
if ($sessionId <= 0) {
    fwrite(STDERR, "Account has no browser session configured.\n");
    exit(1);
}

$result = $poster->publishLinkedInPrimary($sessionId, $payload);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

if (empty($result['success'])) {
    exit(1);
}

echo "LinkedIn post " . ($dryRun ? 'dry-run' : 'publish') . " OK.\n";
if (!empty($result['postUrl'])) {
    echo "Post URL: {$result['postUrl']}\n";
}
