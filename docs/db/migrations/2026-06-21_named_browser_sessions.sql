CREATE TABLE browser_sessions (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,
    name                 TEXT NOT NULL UNIQUE,
    platform             TEXT NOT NULL CHECK (platform IN ('facebook', 'linkedin')),
    storage_state        TEXT NOT NULL,
    status               TEXT NOT NULL DEFAULT 'unknown'
                         CHECK (status IN ('active', 'expired', 'unknown', 'pending')),
    last_verified_at     TEXT,
    last_error           TEXT,
    created_at           TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at           TEXT NOT NULL DEFAULT (datetime('now'))
);

INSERT INTO browser_sessions (name, platform, storage_state, status, last_verified_at, last_error, updated_at)
SELECT
    CASE platform WHEN 'facebook' THEN 'Jamie Facebook' ELSE 'Jamie Linkedin' END,
    platform,
    storage_state,
    status,
    last_verified_at,
    last_error,
    updated_at
FROM platform_sessions;

ALTER TABLE profile_targets ADD COLUMN browser_session_id INTEGER REFERENCES browser_sessions(id);

CREATE TRIGGER migration_abort_unbackfilled_targets
AFTER UPDATE ON profile_targets
FOR EACH ROW
WHEN NEW.browser_session_id IS NULL
BEGIN
  SELECT RAISE(ABORT, 'Migration failed: profile_targets exist without a matching browser session for their platform');
END;

UPDATE profile_targets
SET browser_session_id = (
    SELECT id FROM browser_sessions
    WHERE browser_sessions.platform = profile_targets.platform
    LIMIT 1
);

DROP TRIGGER migration_abort_unbackfilled_targets;

CREATE INDEX idx_profile_targets_session ON profile_targets(browser_session_id);

DROP TABLE platform_sessions;
