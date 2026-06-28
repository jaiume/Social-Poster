-- Session-centric accounts: clean break from profile_targets / playbooks.

DROP TABLE IF EXISTS playbook_publish_events;
DROP TABLE IF EXISTS target_playbooks;

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
);

CREATE UNIQUE INDEX idx_session_accounts_one_root
    ON session_accounts(browser_session_id)
    WHERE account_kind = 'root';

CREATE INDEX idx_session_accounts_session ON session_accounts(browser_session_id);

INSERT INTO session_accounts (browser_session_id, account_kind, display_name, is_active)
SELECT id, 'root', name, 1 FROM browser_sessions;

CREATE TABLE profile_posting_accounts (
    product_profile_id INTEGER NOT NULL REFERENCES product_profiles(id) ON DELETE CASCADE,
    platform           TEXT NOT NULL CHECK (platform IN ('facebook', 'linkedin')),
    session_account_id INTEGER NOT NULL REFERENCES session_accounts(id) ON DELETE RESTRICT,
    PRIMARY KEY (product_profile_id, platform)
);

CREATE TABLE profile_repost_accounts (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    product_profile_id INTEGER NOT NULL REFERENCES product_profiles(id) ON DELETE CASCADE,
    platform           TEXT NOT NULL CHECK (platform IN ('facebook', 'linkedin')),
    session_account_id INTEGER NOT NULL REFERENCES session_accounts(id) ON DELETE RESTRICT,
    sort_order         INTEGER NOT NULL DEFAULT 0,
    UNIQUE (product_profile_id, session_account_id)
);

CREATE INDEX idx_profile_repost_accounts_profile ON profile_repost_accounts(product_profile_id, platform, sort_order);

-- Rebuild post_publications with session_account_id (legacy rows discarded).
DROP TABLE IF EXISTS post_publications;

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
);

CREATE INDEX idx_post_publications_post ON post_publications(post_id);
CREATE INDEX idx_post_publications_account ON post_publications(session_account_id);

DROP TABLE IF EXISTS profile_targets;

DELETE FROM app_settings WHERE setting_key IN (
    'discover_model',
    'discover_max_agent_steps',
    'discover_rediscover_threshold',
    'discover_system_prompt'
);
