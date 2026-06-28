<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\NodeProcess;

class BrowserAutomationService
{
    private const SCRIPT_PLATFORMS = [
        'publish-action.js' => 'facebook',
    ];

    private const CROSS_PLATFORM_SCRIPTS = [
        'publish-action.js' => ['facebook', 'linkedin'],
    ];

    public function __construct(
        private readonly AppSettingsService $settings,
        private readonly BrowserSessionService $sessionService
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function runScript(string $scriptName, int $sessionId, array $payload, ?string $platformOverride = null): array
    {
        $expectedPlatform = $platformOverride ?? self::SCRIPT_PLATFORMS[$scriptName] ?? null;
        if ($expectedPlatform === null && isset(self::CROSS_PLATFORM_SCRIPTS[$scriptName])) {
            $expectedPlatform = $platformOverride ?? self::CROSS_PLATFORM_SCRIPTS[$scriptName][0];
        }
        if ($expectedPlatform === null) {
            return ['success' => false, 'error' => 'Unknown script: ' . $scriptName];
        }

        $sessionData = $this->sessionService->writeDecryptedToTemp($sessionId);
        if ($sessionData === null) {
            return ['success' => false, 'error' => 'No active session configured for id ' . $sessionId];
        }

        if ($sessionData['platform'] !== $expectedPlatform) {
            $this->sessionService->deleteTemp($sessionData['path']);

            return [
                'success' => false,
                'error' => 'Session platform does not match script platform.',
            ];
        }

        $sessionPath = $sessionData['path'];

        try {
            $payload['sessionPath'] = $sessionPath;
            $payload['headless'] = true;
            $payload['timeoutMs'] = $this->settings->getInt('browser_timeout_ms', 30000);
            $payload['screenshotDir'] = BASE_DIR . '/var/logs/browser';

            if (!is_dir($payload['screenshotDir'])) {
                mkdir($payload['screenshotDir'], 0755, true);
            }

            $script = BASE_DIR . '/automation/' . $scriptName;
            if (!is_file($script)) {
                return ['success' => false, 'error' => 'Script not found: ' . $scriptName];
            }

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $proc = proc_open(
                [NodeProcess::nodeBinary(), $script],
                $descriptors,
                $pipes,
                BASE_DIR . '/automation',
                NodeProcess::env()
            );
            if (!is_resource($proc)) {
                return ['success' => false, 'error' => 'Failed to start automation process.'];
            }

            fwrite($pipes[0], json_encode($payload, JSON_THROW_ON_ERROR));
            fclose($pipes[0]);

            [$stdout, $stderr, $timedOut] = $this->readProcessOutput(
                $pipes[1],
                $pipes[2],
                $this->scriptTimeoutMs($scriptName, $payload)
            );
            fclose($pipes[1]);
            fclose($pipes[2]);

            if ($timedOut || ($this->automationOutputLooksComplete($stdout) && proc_get_status($proc)['running'])) {
                proc_terminate($proc);
            }

            if ($timedOut) {
                proc_close($proc);

                return [
                    'success' => false,
                    'error' => 'Browser automation timed out after ' . (int) ($this->scriptTimeoutMs($scriptName, $payload) / 1000) . 's.',
                ];
            }

            $exitCode = proc_close($proc);

            $result = json_decode($stdout ?: '{}', true) ?? [];
            if ($exitCode !== 0 && empty($result['error'])) {
                $result['success'] = false;
                $result['error'] = trim($stderr) ?: 'Script exited with code ' . $exitCode;
            }

            if (!$this->isSuccessfulResult($result) && $this->looksLikeSessionError($result)) {
                $this->sessionService->markExpired(
                    $sessionId,
                    (string) ($result['error'] ?? 'Session expired')
                );
            } elseif ($this->isSuccessfulResult($result) && is_file($sessionPath)) {
                $refreshed = (string) file_get_contents($sessionPath);
                if ($refreshed !== '' && $refreshed !== '{}') {
                    $this->sessionService->importSession($sessionId, $refreshed);
                }
            }

            return $result;
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        } finally {
            $this->sessionService->deleteTemp($sessionPath);
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    private function isSuccessfulResult(array $result): bool
    {
        return !empty($result['success']);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function looksLikeSessionError(array $result): bool
    {
        $errorCode = strtoupper((string) ($result['errorCode'] ?? ''));
        $errorClass = strtolower((string) ($result['errorClass'] ?? ''));
        $error = strtolower((string) ($result['error'] ?? ''));
        $sessionInvalid = (bool) ($result['sessionInvalid'] ?? false);
        if ($sessionInvalid) {
            return true;
        }
        if (in_array($errorCode, ['SESSION_EXPIRED', 'AUTH_REQUIRED', 'CHECKPOINT'], true)) {
            return true;
        }
        if (str_contains($error, 'two-factor') || str_contains($error, '2fa')) {
            return true;
        }
        if (in_array($errorClass, ['session', 'auth'], true)) {
            return true;
        }

        if (str_contains($error, 'composer editor') || str_contains($error, 'selector')) {
            return false;
        }

        return str_contains($error, 'login')
            || str_contains($error, 'not logged')
            || str_contains($error, 'checkpoint')
            || str_contains($error, 'session expired')
            || str_contains($error, 'no active session')
            || preg_match('/session (is )?(expired|invalid|required)/', $error) === 1;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function scriptTimeoutMs(string $scriptName, array $payload = []): int
    {
        $action = (string) ($payload['action'] ?? '');
        if (str_contains($scriptName, 'repost') || str_contains($action, 'repost')) {
            return 300000;
        }

        return 180000;
    }

    /**
     * @param resource $stdoutPipe
     * @param resource $stderrPipe
     * @return array{0: string, 1: string, 2: bool}
     */
    private function readProcessOutput($stdoutPipe, $stderrPipe, int $timeoutMs): array
    {
        stream_set_blocking($stdoutPipe, false);
        stream_set_blocking($stderrPipe, false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + ($timeoutMs / 1000);

        while (microtime(true) < $deadline) {
            if ($this->automationOutputLooksComplete($stdout)) {
                break;
            }

            $read = [];
            if (!feof($stdoutPipe)) {
                $read[] = $stdoutPipe;
            }
            if (!feof($stderrPipe)) {
                $read[] = $stderrPipe;
            }
            if ($read === []) {
                break;
            }

            $remaining = (int) max(1, ($deadline - microtime(true)) * 1_000_000);
            $ready = stream_select($read, $write, $except, 0, min($remaining, 500_000));
            if ($ready === false) {
                break;
            }
            if ($ready === 0) {
                continue;
            }

            foreach ($read as $pipe) {
                $chunk = stream_get_contents($pipe);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                if ($pipe === $stdoutPipe) {
                    $stdout .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
            }
        }

        $timedOut = microtime(true) >= $deadline && (!feof($stdoutPipe) || !feof($stderrPipe));
        if (!$timedOut) {
            $stdout .= stream_get_contents($stdoutPipe) ?: '';
            $stderr .= stream_get_contents($stderrPipe) ?: '';
        }

        return [$stdout, $stderr, $timedOut];
    }

    private function automationOutputLooksComplete(string $stdout): bool
    {
        $trimmed = trim($stdout);
        if ($trimmed === '' || $trimmed[0] !== '{') {
            return false;
        }

        json_decode($trimmed, true);

        return json_last_error() === JSON_ERROR_NONE;
    }
}
