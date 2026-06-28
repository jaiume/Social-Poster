<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\NodeProcess;
use App\Support\ServiceResult;

class SessionCaptureService
{
    private const JOB_TTL_SECONDS = 1800;

    public function __construct(
        private readonly BrowserSessionService $sessions
    ) {
    }

    public function start(int $sessionId): array
    {
        $session = $this->sessions->getById($sessionId);
        if ($session === null) {
            return ServiceResult::failure('Session not found.', 'NOT_FOUND');
        }

        $platform = (string) $session['platform'];

        if (!$this->nodeAvailable()) {
            return ServiceResult::failure(
                'Node.js is not installed. Run: apt install nodejs npm && cd automation && npm install',
                'NODE_MISSING'
            );
        }

        $this->cleanupStaleJobs();
        $jobId = bin2hex(random_bytes(16));
        $workDir = $this->jobDir($jobId);
        mkdir($workDir, 0755, true);

        $script = BASE_DIR . '/automation/capture-session.js';
        if (!is_file($script)) {
            return ServiceResult::failure('Capture script not found.', 'INTERNAL');
        }

        $logFile = $workDir . '/capture.log';
        $browsersPath = NodeProcess::browsersPath();
        $node = NodeProcess::nodeBinary();
        $cmd = sprintf(
            'PLAYWRIGHT_BROWSERS_PATH=%s nohup %s %s %s %s >> %s 2>&1 & echo $!',
            escapeshellarg($browsersPath),
            escapeshellarg($node),
            escapeshellarg($script),
            escapeshellarg($workDir),
            escapeshellarg($platform),
            escapeshellarg($logFile)
        );

        $pid = trim((string) shell_exec($cmd));
        file_put_contents($workDir . '/meta.json', json_encode([
            'jobId' => $jobId,
            'sessionId' => $sessionId,
            'platform' => $platform,
            'sessionName' => $session['name'] ?? '',
            'pid' => $pid,
            'startedAt' => gmdate('c'),
        ], JSON_THROW_ON_ERROR));

        return ServiceResult::success('Capture started.', [
            'jobId' => $jobId,
            'sessionId' => $sessionId,
            'platform' => $platform,
        ]);
    }

    public function getStatus(string $jobId): array
    {
        $workDir = $this->jobDir($jobId);
        if (!is_dir($workDir)) {
            return ServiceResult::failure('Capture job not found.', 'NOT_FOUND');
        }

        $this->finalizeIfComplete($jobId);

        $status = $this->readJson($workDir . '/status.json') ?? [
            'status' => 'starting',
            'message' => 'Waiting for browser…',
        ];

        $meta = $this->readJson($workDir . '/meta.json') ?? [];
        $hasScreenshot = is_file($workDir . '/screenshot.jpg');

        return ServiceResult::success('', [
            'jobId' => $jobId,
            'sessionId' => $meta['sessionId'] ?? null,
            'platform' => $meta['platform'] ?? null,
            'status' => $status['status'] ?? 'starting',
            'message' => $status['message'] ?? '',
            'hasScreenshot' => $hasScreenshot,
            'imported' => ($status['status'] ?? '') === 'imported',
        ]);
    }

    public function sendCommand(string $jobId, array $command): array
    {
        $workDir = $this->jobDir($jobId);
        if (!is_dir($workDir)) {
            return ServiceResult::failure('Capture job not found.', 'NOT_FOUND');
        }

        $status = $this->readJson($workDir . '/status.json');
        if (in_array($status['status'] ?? '', ['complete', 'imported', 'error'], true)) {
            return ServiceResult::failure('Capture job is no longer active.', 'INACTIVE');
        }

        if (($command['action'] ?? '') === 'save' && ($status['status'] ?? '') !== 'ready') {
            return ServiceResult::failure('Log in first, then save the session.', 'INVALID_STATE');
        }

        file_put_contents($workDir . '/command.json', json_encode($command, JSON_THROW_ON_ERROR));

        return ServiceResult::success('Command queued.');
    }

    public function screenshotPath(string $jobId): ?string
    {
        $path = $this->jobDir($jobId) . '/screenshot.jpg';
        if (!is_file($path)) {
            return null;
        }

        return $path;
    }

    public function finalizeIfComplete(string $jobId): void
    {
        $workDir = $this->jobDir($jobId);
        $status = $this->readJson($workDir . '/status.json');
        if (($status['status'] ?? '') !== 'complete') {
            return;
        }

        $storagePath = $workDir . '/storage.json';
        if (!is_file($storagePath)) {
            return;
        }

        $meta = $this->readJson($workDir . '/meta.json');
        $sessionId = (int) ($meta['sessionId'] ?? 0);
        if ($sessionId <= 0) {
            return;
        }

        $json = (string) file_get_contents($storagePath);
        $result = $this->sessions->importSession($sessionId, $json);

        if ($result['success']) {
            $status['status'] = 'imported';
            $status['message'] = 'Session saved.';
            file_put_contents($workDir . '/status.json', json_encode($status, JSON_THROW_ON_ERROR));
        } else {
            $status['status'] = 'error';
            $status['message'] = $result['message'];
            file_put_contents($workDir . '/status.json', json_encode($status, JSON_THROW_ON_ERROR));
        }
    }

    private function jobDir(string $jobId): string
    {
        return BASE_DIR . '/var/capture/' . preg_replace('/[^a-f0-9]/', '', $jobId);
    }

    private function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    private function nodeAvailable(): bool
    {
        return NodeProcess::nodeBinary() !== 'node' || is_executable('/usr/bin/node');
    }

    private function cleanupStaleJobs(): void
    {
        $base = BASE_DIR . '/var/capture';
        if (!is_dir($base)) {
            mkdir($base, 0755, true);
            return;
        }

        $cutoff = time() - self::JOB_TTL_SECONDS;
        foreach (scandir($base) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $dir = $base . '/' . $entry;
            if (!is_dir($dir)) {
                continue;
            }
            if (filemtime($dir) < $cutoff) {
                $this->removeDir($dir);
            }
        }
    }

    private function removeDir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
