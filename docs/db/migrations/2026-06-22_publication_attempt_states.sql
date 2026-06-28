CREATE TABLE publication_attempt_states (
    id                      INTEGER PRIMARY KEY AUTOINCREMENT,
    publication_id          INTEGER NOT NULL REFERENCES post_publications(id) ON DELETE CASCADE,
    platform                TEXT NOT NULL CHECK (platform IN ('facebook', 'linkedin')),
    action                  TEXT NOT NULL CHECK (action IN ('post', 'repost')),
    state                   TEXT NOT NULL,
    status                  TEXT NOT NULL CHECK (status IN ('pending', 'success', 'failed', 'needs_review')),
    attempt_no              INTEGER NOT NULL DEFAULT 1,
    operator_target_url     TEXT,
    resolved_start_url      TEXT,
    resolver_reason_code    TEXT,
    resolver_confidence     TEXT CHECK (resolver_confidence IN ('strong', 'weak', 'none')),
    resolver_trace_json     TEXT,
    verification_confidence TEXT CHECK (verification_confidence IN ('strong', 'weak', 'none')),
    evidence_json           TEXT,
    error_code              TEXT,
    error_class             TEXT,
    retryable               INTEGER CHECK (retryable IN (0, 1)),
    started_at              TEXT NOT NULL DEFAULT (datetime('now')),
    ended_at                TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX idx_attempt_states_publication ON publication_attempt_states(publication_id);
CREATE INDEX idx_attempt_states_status ON publication_attempt_states(status);
CREATE INDEX idx_attempt_states_started ON publication_attempt_states(started_at DESC);
