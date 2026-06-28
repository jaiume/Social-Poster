#!/usr/bin/env php
<?php

declare(strict_types=1);

define('BASE_DIR', dirname(__DIR__));

require BASE_DIR . '/vendor/autoload.php';

use App\DAO\PostDao;
use App\DAO\ProfilePostingAccountDao;
use App\DAO\ProfileRepostAccountDao;
use App\DAO\SessionAccountDao;
use App\Services\PosterAutomationService;
use App\Services\ProfileAccountService;
use App\Support\SessionAccountUrls;

$postId = (int) ($argv[1] ?? 0);
$primaryPostUrl = (string) ($argv[2] ?? '');
if ($postId <= 0 || $primaryPostUrl === '') {
    fwrite(STDERR, "Usage: php bin/test-facebook-repost.php <post_id> <primary_post_url>\n");
    exit(1);
}

$container = require BASE_DIR . '/config/container.php';
$postDao = $container->get(PostDao::class);
$postingDao = $container->get(ProfilePostingAccountDao::class);
$repostDao = $container->get(ProfileRepostAccountDao::class);
$accountDao = $container->get(SessionAccountDao::class);
$profileAccounts = $container->get(ProfileAccountService::class);
$poster = $container->get(PosterAutomationService::class);

$post = $postDao->findById($postId);
if ($post === null) {
    fwrite(STDERR, "Post {$postId} not found.\n");
    exit(1);
}

$profileId = (int) $post['product_profile_id'];
$postingSlot = $postingDao->findForProfilePlatform($profileId, 'facebook');
if ($postingSlot === null) {
    fwrite(STDERR, "No Facebook posting account for post {$postId}.\n");
    exit(1);
}

$postingAccount = $profileAccounts->enrichAccount($accountDao->findById((int) $postingSlot['session_account_id']) ?? []);
$repostRow = null;
foreach ($repostDao->findByProfileId($profileId) as $row) {
    if (($row['platform'] ?? '') === 'facebook') {
        $repostRow = $row;
        break;
    }
}
if ($repostRow === null) {
    fwrite(STDERR, "Post {$postId} needs a Facebook repost account.\n");
    exit(1);
}

$repostAccount = $profileAccounts->enrichAccount($accountDao->findById((int) $repostRow['session_account_id']) ?? []);

$content = trim((string) ($post['content_facebook'] ?? $post['content_linkedin'] ?? ''));
if ($content === '') {
    fwrite(STDERR, "Post {$postId} has no content.\n");
    exit(1);
}

$personalUrl = SessionAccountUrls::personalContextUrl('facebook');
$primaryPageBrand = preg_replace('/\s+Facebook$/i', '', (string) ($postingAccount['display_name'] ?? ''));
$payload = [
    'text' => $content,
    'primaryPostUrl' => $primaryPostUrl,
    'primaryPageUrl' => (string) $postingAccount['bootstrap_url'],
    'primaryPageBrand' => $primaryPageBrand !== '' ? $primaryPageBrand : null,
    'pageUrl' => $personalUrl,
    'operatorStartUrl' => $personalUrl,
    'personalContextUrl' => $personalUrl,
    'targetPageUrl' => $personalUrl,
    'accountKind' => 'root',
];

echo "Facebook repost for post {$postId}\n";
echo "Primary URL: {$primaryPostUrl}\n";
echo "Primary page: {$postingAccount['bootstrap_url']}\n";
echo "Repost account: {$repostAccount['display_name']}\n";

$sessionId = (int) ($repostAccount['browser_session_id'] ?? 0);
if ($sessionId <= 0) {
    fwrite(STDERR, "Repost account has no browser session configured.\n");
    exit(1);
}

$result = $poster->publishFacebookRepost($sessionId, $payload);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

if (empty($result['success'])) {
    exit(1);
}

echo "Facebook repost OK.\n";
if (!empty($result['postUrl'])) {
    echo "Verified at: {$result['postUrl']}\n";
}
