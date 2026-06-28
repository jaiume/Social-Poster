<?php

declare(strict_types=1);

namespace App\DAO;

class PublicationAttemptStateDao extends BaseDao
{
    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO publication_attempt_states (
                publication_id, platform, action, state, status, attempt_no,
                operator_target_url, resolved_start_url, resolver_reason_code, resolver_confidence,
                resolver_trace_json, verification_confidence, evidence_json,
                error_code, error_class, retryable, started_at, ended_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (int) $data['publication_id'],
            (string) $data['platform'],
            (string) $data['action'],
            (string) ($data['state'] ?? 'UNKNOWN'),
            (string) ($data['status'] ?? 'failed'),
            (int) ($data['attempt_no'] ?? 1),
            $data['operator_target_url'] ?? null,
            $data['resolved_start_url'] ?? null,
            $data['resolver_reason_code'] ?? null,
            $data['resolver_confidence'] ?? null,
            $data['resolver_trace_json'] ?? null,
            $data['verification_confidence'] ?? null,
            $data['evidence_json'] ?? null,
            $data['error_code'] ?? null,
            $data['error_class'] ?? null,
            isset($data['retryable']) ? ((bool) $data['retryable'] ? 1 : 0) : null,
            $data['started_at'] ?? gmdate('c'),
            $data['ended_at'] ?? gmdate('c'),
        ]);

        return (int) $this->db->lastInsertId();
    }
}
