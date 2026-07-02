-- Remove automation schema: sessions, publications, reposts; reshape posting accounts.

DROP TABLE IF EXISTS publication_attempt_states;
DROP TABLE IF EXISTS post_publications;
DROP TABLE IF EXISTS profile_repost_accounts;

CREATE TABLE posting_accounts (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    platform     TEXT NOT NULL CHECK (platform IN ('facebook', 'linkedin')),
    account_kind TEXT NOT NULL CHECK (account_kind IN ('root', 'sub')),
    sub_page_id  TEXT,
    display_name TEXT NOT NULL,
    is_active    INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    created_at   TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at   TEXT NOT NULL DEFAULT (datetime('now')),
    CHECK (
        (account_kind = 'root' AND sub_page_id IS NULL)
        OR (account_kind = 'sub' AND sub_page_id IS NOT NULL AND TRIM(sub_page_id) != '')
    )
);

INSERT INTO posting_accounts (id, platform, account_kind, sub_page_id, display_name, is_active, created_at, updated_at)
SELECT sa.id, bs.platform, sa.account_kind, sa.sub_page_id, sa.display_name, sa.is_active, sa.created_at, sa.updated_at
FROM session_accounts sa
JOIN browser_sessions bs ON bs.id = sa.browser_session_id;

CREATE INDEX idx_posting_accounts_platform ON posting_accounts(platform, is_active);

CREATE TABLE profile_posting_accounts_new (
    product_profile_id INTEGER NOT NULL REFERENCES product_profiles(id) ON DELETE CASCADE,
    platform           TEXT NOT NULL CHECK (platform IN ('facebook', 'linkedin')),
    posting_account_id INTEGER NOT NULL REFERENCES posting_accounts(id) ON DELETE RESTRICT,
    PRIMARY KEY (product_profile_id, platform)
);

INSERT INTO profile_posting_accounts_new (product_profile_id, platform, posting_account_id)
SELECT product_profile_id, platform, session_account_id
FROM profile_posting_accounts;

DROP TABLE profile_posting_accounts;
ALTER TABLE profile_posting_accounts_new RENAME TO profile_posting_accounts;

DROP TABLE session_accounts;
DROP TABLE browser_sessions;

UPDATE posts SET status = 'approved' WHERE status = 'posted';

CREATE TABLE posts_new (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    product_profile_id INTEGER NOT NULL REFERENCES product_profiles(id) ON DELETE CASCADE,
    status             TEXT NOT NULL DEFAULT 'draft'
                       CHECK (status IN ('draft', 'approved', 'archived')),
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
    id, product_profile_id, status, content_facebook, content_linkedin, image_path, image_error,
    source_urls_json, ai_model, ai_prompt_snapshot, ai_tool_calls_json, ai_error, generated_at,
    created_at, updated_at
)
SELECT
    id, product_profile_id, status, content_facebook, content_linkedin, image_path, image_error,
    source_urls_json, ai_model, ai_prompt_snapshot, ai_tool_calls_json, ai_error, generated_at,
    created_at, updated_at
FROM posts;

DROP TABLE posts;
ALTER TABLE posts_new RENAME TO posts;

CREATE INDEX idx_posts_profile_created ON posts(product_profile_id, created_at DESC);
CREATE INDEX idx_posts_status ON posts(status);

CREATE TABLE product_profiles_new (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    name                TEXT NOT NULL,
    slug                TEXT NOT NULL UNIQUE,
    is_active           INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    posting_guidance    TEXT,
    image_guidance      TEXT,
    generate_post_image INTEGER NOT NULL DEFAULT 0 CHECK (generate_post_image IN (0, 1)),
    created_at          TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at          TEXT NOT NULL DEFAULT (datetime('now'))
);

INSERT INTO product_profiles_new (
    id, name, slug, is_active, posting_guidance, image_guidance, generate_post_image, created_at, updated_at
)
SELECT id, name, slug, is_active, posting_guidance, image_guidance, generate_post_image, created_at, updated_at
FROM product_profiles;

DROP TABLE product_profiles;
ALTER TABLE product_profiles_new RENAME TO product_profiles;

DELETE FROM app_settings WHERE setting_key IN (
    'browser_timeout_ms',
    'browser_repost_delay_ms',
    'browser_headless',
    'cron_posting_enabled',
    'posting_window_grace_minutes'
);
