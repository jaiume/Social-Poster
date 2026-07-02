CREATE TABLE app_settings (
    setting_key   TEXT PRIMARY KEY,
    setting_value TEXT NOT NULL,
    is_secret     INTEGER NOT NULL DEFAULT 0 CHECK (is_secret IN (0, 1)),
    updated_at    TEXT NOT NULL DEFAULT (datetime('now'))
);;

CREATE TABLE posts (
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
);;

CREATE TABLE product_profiles (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    name                TEXT NOT NULL,
    slug                TEXT NOT NULL UNIQUE,
    is_active           INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    posting_guidance    TEXT,
    image_guidance      TEXT,
    generate_post_image INTEGER NOT NULL DEFAULT 0 CHECK (generate_post_image IN (0, 1)),
    created_at          TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at          TEXT NOT NULL DEFAULT (datetime('now'))
);;

CREATE TABLE profile_sources (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    product_profile_id INTEGER NOT NULL REFERENCES product_profiles(id) ON DELETE CASCADE,
    url                TEXT NOT NULL,
    label              TEXT,
    sort_order         INTEGER NOT NULL DEFAULT 0,
    is_active          INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    created_at         TEXT NOT NULL DEFAULT (datetime('now'))
);;

CREATE TABLE profile_image_assets (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    product_profile_id INTEGER NOT NULL REFERENCES product_profiles(id) ON DELETE CASCADE,
    label              TEXT,
    file_path          TEXT NOT NULL,
    mime_type          TEXT NOT NULL,
    sort_order         INTEGER NOT NULL DEFAULT 0,
    created_at         TEXT NOT NULL DEFAULT (datetime('now'))
);;

CREATE INDEX idx_profile_image_assets_profile ON profile_image_assets(product_profile_id);;

CREATE TABLE schema_migrations (
    filename TEXT PRIMARY KEY,
    applied_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE task_jobs (
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

CREATE INDEX idx_posts_profile_created ON posts(product_profile_id, created_at DESC);
CREATE INDEX idx_posts_status ON posts(status);
CREATE INDEX idx_task_jobs_profile_status ON task_jobs(product_profile_id, status);
CREATE INDEX idx_task_jobs_post_status ON task_jobs(post_id, status);
CREATE INDEX idx_task_jobs_status_updated ON task_jobs(status, updated_at);
