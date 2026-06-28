-- Add Playwright script storage for target automation
ALTER TABLE target_playbooks ADD COLUMN script_source TEXT;
ALTER TABLE target_playbooks ADD COLUMN script_hash TEXT;
