ALTER TABLE product_profiles ADD COLUMN generate_post_image INTEGER NOT NULL DEFAULT 0 CHECK (generate_post_image IN (0, 1));
ALTER TABLE posts ADD COLUMN image_path TEXT;

INSERT OR IGNORE INTO app_settings (setting_key, setting_value, is_secret) VALUES
    ('openrouter_image_model', 'black-forest-labs/flux-1.1-schnell', 0);
