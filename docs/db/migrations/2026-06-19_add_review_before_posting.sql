ALTER TABLE product_profiles ADD COLUMN review_before_posting INTEGER NOT NULL DEFAULT 0 CHECK (review_before_posting IN (0, 1));
