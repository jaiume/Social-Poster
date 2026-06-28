ALTER TABLE product_profiles ADD COLUMN image_guidance TEXT;

INSERT OR IGNORE INTO app_settings (setting_key, setting_value, is_secret) VALUES
    ('openrouter_post_system_prompt', 'You research sources using the provided tools and write social media post copy. Apply the post guidance and instructions in the user message. Return only the response format requested.', 0),
    ('openrouter_image_system_prompt', 'You generate a social media marketing image. Apply the image guidance and scene description provided in the user message.', 0);

UPDATE app_settings
SET setting_value = (SELECT setting_value FROM app_settings WHERE setting_key = 'openrouter_system_prompt')
WHERE setting_key = 'openrouter_post_system_prompt'
  AND EXISTS (SELECT 1 FROM app_settings WHERE setting_key = 'openrouter_system_prompt' AND setting_value != '')
  AND setting_value = 'You research sources using the provided tools and write social media post copy. Apply the post guidance and instructions in the user message. Return only the response format requested.';
