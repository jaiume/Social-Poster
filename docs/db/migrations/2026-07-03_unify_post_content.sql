CREATE TABLE posts_new (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    product_profile_id INTEGER NOT NULL REFERENCES product_profiles(id) ON DELETE CASCADE,
    status             TEXT NOT NULL DEFAULT 'draft'
                       CHECK (status IN ('draft', 'approved', 'archived')),
    content            TEXT,
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
    id, product_profile_id, status, content, image_path, image_error,
    source_urls_json, ai_model, ai_prompt_snapshot, ai_tool_calls_json, ai_error, generated_at,
    created_at, updated_at
)
SELECT
    id,
    product_profile_id,
    status,
    COALESCE(
        NULLIF(TRIM(content_facebook), ''),
        NULLIF(TRIM(content_linkedin), ''),
        content_facebook,
        content_linkedin
    ),
    image_path,
    image_error,
    source_urls_json,
    ai_model,
    ai_prompt_snapshot,
    ai_tool_calls_json,
    ai_error,
    generated_at,
    created_at,
    updated_at
FROM posts;

DROP TABLE posts;
ALTER TABLE posts_new RENAME TO posts;

CREATE INDEX idx_posts_profile_created ON posts(product_profile_id, created_at DESC);
CREATE INDEX idx_posts_status ON posts(status);
