-- Remove cron scheduling tables and deprecated columns.
--
-- IMPORTANT: Disable foreign keys before dropping product_profiles. Child tables
-- (profile_sources, profile_targets, etc.) reference product_profiles with
-- ON DELETE CASCADE; dropping the parent table would wipe all child rows.

DROP TABLE IF EXISTS profile_post_schedule;
DROP TABLE IF EXISTS posting_runs;

DELETE FROM app_settings WHERE setting_key = 'posting_window_grace_minutes';

PRAGMA foreign_keys = OFF;

CREATE TABLE product_profiles_new (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    name               TEXT NOT NULL,
    slug               TEXT NOT NULL UNIQUE,
    is_active          INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    posting_timezone   TEXT NOT NULL DEFAULT 'Europe/London',
    posting_guidance   TEXT,
    image_guidance     TEXT,
    generate_post_image INTEGER NOT NULL DEFAULT 0 CHECK (generate_post_image IN (0, 1)),
    created_at         TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at         TEXT NOT NULL DEFAULT (datetime('now'))
);

INSERT INTO product_profiles_new (
    id, name, slug, is_active, posting_timezone, posting_guidance, image_guidance,
    generate_post_image, created_at, updated_at
)
SELECT
    id, name, slug, is_active, posting_timezone, posting_guidance, image_guidance,
    generate_post_image, created_at, updated_at
FROM product_profiles;

DROP TABLE product_profiles;
ALTER TABLE product_profiles_new RENAME TO product_profiles;

PRAGMA foreign_keys = ON;

CREATE TABLE task_jobs_new (
    id                  TEXT PRIMARY KEY,
    recipe              TEXT NOT NULL,
    payload_json        TEXT NOT NULL DEFAULT '{}',
    status              TEXT NOT NULL DEFAULT 'pending'
                        CHECK (status IN ('pending', 'running', 'completed', 'failed', 'cancelled')),
    steps_json          TEXT NOT NULL DEFAULT '[]',
    current_step        INTEGER NOT NULL DEFAULT 0,
    pid                 INTEGER,
    error_message       TEXT,
    result_json         TEXT NOT NULL DEFAULT '{}',
    product_profile_id  INTEGER REFERENCES product_profiles(id) ON DELETE SET NULL,
    post_id             INTEGER REFERENCES posts(id) ON DELETE SET NULL,
    created_at          TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at          TEXT NOT NULL DEFAULT (datetime('now')),
    started_at          TEXT,
    finished_at         TEXT
);

INSERT INTO task_jobs_new (
    id, recipe, payload_json, status, steps_json, current_step, pid, error_message,
    result_json, product_profile_id, post_id, created_at, updated_at, started_at, finished_at
)
SELECT
    id, recipe, payload_json, status, steps_json, current_step, pid, error_message,
    result_json, product_profile_id, post_id, created_at, updated_at, started_at, finished_at
FROM task_jobs;

DROP TABLE task_jobs;
ALTER TABLE task_jobs_new RENAME TO task_jobs;

CREATE INDEX idx_task_jobs_profile_status ON task_jobs(product_profile_id, status);
CREATE INDEX idx_task_jobs_post_status ON task_jobs(post_id, status);
CREATE INDEX idx_task_jobs_status_updated ON task_jobs(status, updated_at);
