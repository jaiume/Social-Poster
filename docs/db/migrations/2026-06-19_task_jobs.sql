CREATE TABLE IF NOT EXISTS task_jobs (
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
    schedule_date       TEXT,
    created_at          TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at          TEXT NOT NULL DEFAULT (datetime('now')),
    started_at          TEXT,
    finished_at         TEXT
);

CREATE INDEX IF NOT EXISTS idx_task_jobs_profile_schedule_status
    ON task_jobs(product_profile_id, schedule_date, status);

CREATE INDEX IF NOT EXISTS idx_task_jobs_post_status
    ON task_jobs(post_id, status);

CREATE INDEX IF NOT EXISTS idx_task_jobs_status_updated
    ON task_jobs(status, updated_at);
