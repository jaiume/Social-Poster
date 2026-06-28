CREATE TABLE product_profiles (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,
    name                 TEXT NOT NULL,
    slug                 TEXT NOT NULL UNIQUE,
    is_active            INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    posting_window_start TEXT NOT NULL,
    posting_window_end   TEXT NOT NULL,
    posting_timezone     TEXT NOT NULL DEFAULT 'Europe/London',
    posting_guidance     TEXT,
    post_weekdays_only   INTEGER NOT NULL DEFAULT 1 CHECK (post_weekdays_only IN (0, 1)),
    last_posted_at       TEXT,
    created_at           TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at           TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE profile_sources (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    product_profile_id INTEGER NOT NULL REFERENCES product_profiles(id) ON DELETE CASCADE,
    url                TEXT NOT NULL,
    label              TEXT,
    sort_order         INTEGER NOT NULL DEFAULT 0,
    is_active          INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    created_at         TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX idx_profile_sources_profile ON profile_sources(product_profile_id);

CREATE TABLE profile_targets (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    product_profile_id INTEGER NOT NULL REFERENCES product_profiles(id) ON DELETE CASCADE,
    platform           TEXT NOT NULL CHECK (platform IN ('facebook', 'linkedin')),
    role               TEXT NOT NULL CHECK (role IN ('primary', 'repost')),
    display_name       TEXT NOT NULL,
    page_url           TEXT NOT NULL,
    is_active          INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    sort_order         INTEGER NOT NULL DEFAULT 0,
    created_at         TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at         TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX idx_profile_targets_profile ON profile_targets(product_profile_id);

CREATE UNIQUE INDEX uq_profile_primary_target
    ON profile_targets(product_profile_id, platform)
    WHERE role = 'primary' AND is_active = 1;

CREATE TABLE platform_sessions (
    platform         TEXT PRIMARY KEY CHECK (platform IN ('facebook', 'linkedin')),
    storage_state    TEXT NOT NULL,
    status           TEXT NOT NULL DEFAULT 'unknown'
                     CHECK (status IN ('active', 'expired', 'unknown')),
    last_verified_at TEXT,
    last_error       TEXT,
    updated_at       TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE app_settings (
    setting_key   TEXT PRIMARY KEY,
    setting_value TEXT NOT NULL,
    is_secret     INTEGER NOT NULL DEFAULT 0 CHECK (is_secret IN (0, 1)),
    updated_at    TEXT NOT NULL DEFAULT (datetime('now'))
);

INSERT INTO app_settings (setting_key, setting_value, is_secret) VALUES
    ('openrouter_api_key', '', 1),
    ('openrouter_model', 'openai/gpt-4o-mini', 0),
    ('openrouter_max_tool_calls', '10', 0),
    ('openrouter_max_agent_turns', '15', 0),
    ('openrouter_system_prompt', 'You are a social media copywriter. Generate engaging, accurate posts based on product information. Return only valid JSON with facebook and linkedin keys when asked.', 0),
    ('openrouter_max_history_posts', '10', 0),
    ('browser_headless', 'true', 0),
    ('browser_timeout_ms', '30000', 0),
    ('posting_max_retries_per_day', '3', 0),
    ('posting_window_grace_minutes', '15', 0),
    ('browser_action_delay_ms_min', '300', 0),
    ('browser_action_delay_ms_max', '900', 0),
    ('browser_repost_delay_ms', '45000', 0);

CREATE TABLE posts (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    product_profile_id INTEGER NOT NULL REFERENCES product_profiles(id) ON DELETE CASCADE,
    status             TEXT NOT NULL DEFAULT 'generating'
                       CHECK (status IN ('generating', 'ready', 'publishing', 'published', 'partial', 'failed')),
    attempt_count      INTEGER NOT NULL DEFAULT 0,
    schedule_date      TEXT,
    content_facebook   TEXT,
    content_linkedin   TEXT,
    source_urls_json   TEXT NOT NULL DEFAULT '[]',
    ai_model           TEXT,
    ai_prompt_snapshot TEXT,
    ai_tool_calls_json TEXT,
    ai_error           TEXT,
    generated_at       TEXT,
    created_at         TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at         TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX idx_posts_profile_created ON posts(product_profile_id, created_at DESC);
CREATE INDEX idx_posts_status ON posts(status);

CREATE TABLE post_publications (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id               INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
    profile_target_id     INTEGER NOT NULL REFERENCES profile_targets(id),
    action                TEXT NOT NULL CHECK (action IN ('post', 'repost')),
    status                TEXT NOT NULL DEFAULT 'pending'
                          CHECK (status IN ('pending', 'success', 'failed', 'skipped')),
    external_post_url     TEXT,
    browser_method        TEXT,
    parent_publication_id INTEGER REFERENCES post_publications(id),
    error_code            TEXT,
    error_message         TEXT,
    attempted_at          TEXT,
    completed_at          TEXT,
    created_at            TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX idx_post_publications_post ON post_publications(post_id);
CREATE INDEX idx_post_publications_target ON post_publications(profile_target_id);

CREATE TABLE profile_post_schedule (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    product_profile_id INTEGER NOT NULL REFERENCES product_profiles(id) ON DELETE CASCADE,
    schedule_date      TEXT NOT NULL,
    scheduled_at       TEXT NOT NULL,
    created_at         TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (product_profile_id, schedule_date)
);

CREATE INDEX idx_profile_post_schedule_due ON profile_post_schedule(scheduled_at);

CREATE TABLE posting_runs (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    started_at       TEXT NOT NULL,
    finished_at      TEXT,
    profiles_checked INTEGER NOT NULL DEFAULT 0,
    profiles_posted  INTEGER NOT NULL DEFAULT 0,
    status           TEXT NOT NULL DEFAULT 'running'
                     CHECK (status IN ('running', 'completed', 'failed')),
    summary          TEXT
);

CREATE INDEX idx_posting_runs_started ON posting_runs(started_at DESC);
