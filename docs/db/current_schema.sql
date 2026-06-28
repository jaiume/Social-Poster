CREATE TABLE app_settings (
    setting_key   TEXT PRIMARY KEY,
    setting_value TEXT NOT NULL,
    is_secret     INTEGER NOT NULL DEFAULT 0 CHECK (is_secret IN (0, 1)),
    updated_at    TEXT NOT NULL DEFAULT (datetime('now'))
);;

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
);;

CREATE TABLE session_accounts (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    browser_session_id INTEGER NOT NULL REFERENCES browser_sessions(id) ON DELETE CASCADE,
    account_kind       TEXT NOT NULL CHECK (account_kind IN ('root', 'sub')),
    sub_page_id        TEXT,
    display_name       TEXT NOT NULL,
    is_active          INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    created_at         TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at         TEXT NOT NULL DEFAULT (datetime('now')),
    CHECK (
        (account_kind = 'root' AND sub_page_id IS NULL)
        OR (account_kind = 'sub' AND sub_page_id IS NOT NULL AND TRIM(sub_page_id) != '')
    )
);;

CREATE UNIQUE INDEX idx_session_accounts_one_root
    ON session_accounts(browser_session_id)
    WHERE account_kind = 'root';

CREATE INDEX idx_session_accounts_session ON session_accounts(browser_session_id);;

CREATE TABLE profile_posting_accounts (
    product_profile_id INTEGER NOT NULL REFERENCES product_profiles(id) ON DELETE CASCADE,
    platform           TEXT NOT NULL CHECK (platform IN ('facebook', 'linkedin')),
    session_account_id INTEGER NOT NULL REFERENCES session_accounts(id) ON DELETE RESTRICT,
    PRIMARY KEY (product_profile_id, platform)
);;

CREATE TABLE profile_repost_accounts (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    product_profile_id INTEGER NOT NULL REFERENCES product_profiles(id) ON DELETE CASCADE,
    platform           TEXT NOT NULL CHECK (platform IN ('facebook', 'linkedin')),
    session_account_id INTEGER NOT NULL REFERENCES session_accounts(id) ON DELETE RESTRICT,
    sort_order         INTEGER NOT NULL DEFAULT 0,
    UNIQUE (product_profile_id, session_account_id)
);;

CREATE INDEX idx_profile_repost_accounts_profile ON profile_repost_accounts(product_profile_id, platform, sort_order);;

CREATE TABLE post_publications (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id               INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
    session_account_id    INTEGER NOT NULL REFERENCES session_accounts(id),
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
    publish_batch_id      TEXT,
    created_at            TEXT NOT NULL DEFAULT (datetime('now'))
);;

CREATE INDEX idx_post_publications_post ON post_publications(post_id);;
CREATE INDEX idx_post_publications_account ON post_publications(session_account_id);;

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
);;

CREATE INDEX idx_attempt_states_publication ON publication_attempt_states(publication_id);;
CREATE INDEX idx_attempt_states_status ON publication_attempt_states(status);;
CREATE INDEX idx_attempt_states_started ON publication_attempt_states(started_at DESC);;

CREATE TABLE posts (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    product_profile_id INTEGER NOT NULL REFERENCES product_profiles(id) ON DELETE CASCADE,
    status             TEXT NOT NULL DEFAULT 'draft'
                       CHECK (status IN ('draft', 'approved', 'posted', 'archived')),
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
);;

CREATE TABLE product_profiles (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    name                TEXT NOT NULL,
    slug                TEXT NOT NULL UNIQUE,
    is_active           INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    posting_timezone    TEXT NOT NULL DEFAULT 'Europe/London',
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
