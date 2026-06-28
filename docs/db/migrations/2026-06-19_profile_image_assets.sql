CREATE TABLE profile_image_assets (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    product_profile_id INTEGER NOT NULL REFERENCES product_profiles(id) ON DELETE CASCADE,
    label              TEXT,
    file_path          TEXT NOT NULL,
    mime_type          TEXT NOT NULL,
    sort_order         INTEGER NOT NULL DEFAULT 0,
    created_at         TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX idx_profile_image_assets_profile ON profile_image_assets(product_profile_id);
