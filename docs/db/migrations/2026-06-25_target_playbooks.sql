CREATE TABLE target_playbooks (
    id                           INTEGER PRIMARY KEY AUTOINCREMENT,
    profile_target_id            INTEGER NOT NULL REFERENCES profile_targets(id) ON DELETE CASCADE,
    platform                     TEXT NOT NULL CHECK (platform IN ('facebook', 'linkedin')),
    action                       TEXT NOT NULL CHECK (action IN ('post', 'repost')),
    version                      INTEGER NOT NULL DEFAULT 1,
    status                       TEXT NOT NULL CHECK (status IN ('discovering', 'active', 'failed', 'superseded')),
    flow_profile                 TEXT NOT NULL,
    playbook_json                TEXT NOT NULL DEFAULT '{}',
    discovery_trace_json         TEXT,
    validation_result_json       TEXT,
    prior_evidence_snapshot_json TEXT,
    session_id                   INTEGER REFERENCES browser_sessions(id),
    discovered_at                TEXT,
    validated_at                 TEXT,
    last_error                   TEXT,
    failure_count                INTEGER NOT NULL DEFAULT 0,
    created_at                   TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at                   TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE UNIQUE INDEX idx_target_playbooks_active
    ON target_playbooks(profile_target_id, platform, action)
    WHERE status = 'active';

CREATE INDEX idx_target_playbooks_target ON target_playbooks(profile_target_id);

CREATE TABLE playbook_publish_events (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,
    profile_target_id    INTEGER NOT NULL REFERENCES profile_targets(id) ON DELETE CASCADE,
    playbook_id          INTEGER REFERENCES target_playbooks(id) ON DELETE SET NULL,
    publication_id       INTEGER REFERENCES post_publications(id) ON DELETE SET NULL,
    platform             TEXT NOT NULL,
    action               TEXT NOT NULL CHECK (action IN ('post', 'repost')),
    outcome              TEXT NOT NULL CHECK (outcome IN ('success', 'failure')),
    playbook_version     INTEGER,
    start_url_used       TEXT,
    error_code           TEXT,
    error_message        TEXT,
    failed_state         TEXT,
    evidence_json        TEXT NOT NULL DEFAULT '{}',
    created_at           TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX idx_playbook_events_target ON playbook_publish_events(profile_target_id, created_at DESC);
CREATE INDEX idx_playbook_events_playbook ON playbook_publish_events(playbook_id, created_at DESC);

INSERT OR IGNORE INTO app_settings (setting_key, setting_value, is_secret) VALUES
    ('discover_model', '', 0),
    ('discover_max_agent_steps', '30', 0),
    ('discover_rediscover_threshold', '3', 0);
