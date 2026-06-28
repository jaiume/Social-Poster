-- Restore EntryZen profile sources lost when product_profiles was dropped
-- without PRAGMA foreign_keys=OFF (see 2026-06-20_remove_scheduling_schema.sql).

INSERT INTO profile_sources (product_profile_id, url, label, sort_order, is_active)
SELECT 15, 'https://entryzen.com', 'EntryZen', 0, 1
WHERE EXISTS (SELECT 1 FROM product_profiles WHERE id = 15)
  AND NOT EXISTS (
      SELECT 1 FROM profile_sources
      WHERE product_profile_id = 15 AND url = 'https://entryzen.com'
  );
