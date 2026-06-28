-- Migrate post statuses to workflow model and add publish_batch_id on publications.

ALTER TABLE post_publications ADD COLUMN publish_batch_id TEXT;

CREATE TABLE posts_new (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    product_profile_id INTEGER NOT NULL REFERENCES product_profiles(id) ON DELETE CASCADE,
    status             TEXT NOT NULL DEFAULT 'draft'
                       CHECK (status IN ('draft', 'approved', 'posted', 'archived')),
    attempt_count      INTEGER NOT NULL DEFAULT 0,
    content_facebook   TEXT,
    content_linkedin   TEXT,
    image_path         TEXT,
    image_error        TEXT,
    source_urls_json   TEXT NOT NULL DEFAULT '[]',
    ai_model           TEXT,
    ai_prompt_snapshot TEXT,
    ai_tool_calls_json TEXT,
    ai_error           TEXT,
    generated_at       TEXT,
    created_at         TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at         TEXT NOT NULL DEFAULT (datetime('now'))
);

INSERT INTO posts_new (
    id, product_profile_id, status, attempt_count,
    content_facebook, content_linkedin, image_path, image_error,
    source_urls_json, ai_model, ai_prompt_snapshot, ai_tool_calls_json,
    ai_error, generated_at, created_at, updated_at
)
SELECT
    id, product_profile_id,
    CASE status
        WHEN 'generating' THEN 'draft'
        WHEN 'ready' THEN 'draft'
        WHEN 'failed' THEN 'draft'
        WHEN 'publishing' THEN 'approved'
        WHEN 'published' THEN 'posted'
        WHEN 'partial' THEN 'posted'
        ELSE 'draft'
    END,
    attempt_count,
    content_facebook, content_linkedin, image_path, image_error,
    source_urls_json, ai_model, ai_prompt_snapshot, ai_tool_calls_json,
    ai_error, generated_at, created_at, updated_at
FROM posts;

DROP TABLE posts;
ALTER TABLE posts_new RENAME TO posts;

CREATE INDEX idx_posts_profile_created ON posts(product_profile_id, created_at DESC);
CREATE INDEX idx_posts_status ON posts(status);

INSERT OR IGNORE INTO app_settings (setting_key, setting_value, is_secret)
VALUES ('cron_posting_enabled', '0', 0);
