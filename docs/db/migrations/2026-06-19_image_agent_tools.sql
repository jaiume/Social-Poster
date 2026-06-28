INSERT OR IGNORE INTO app_settings (setting_key, setting_value, is_secret) VALUES
    ('openrouter_image_agent_model', '', 0);

UPDATE app_settings
SET setting_value = 'You prepare prompts for social media image generation. Use the fetch tools to research brand visuals when needed. Apply the image guidance and scene description in the user message. When ready, respond with only the final image generation prompt as plain text.'
WHERE setting_key = 'openrouter_image_system_prompt'
  AND setting_value = 'You generate a social media marketing image. Apply the image guidance and scene description provided in the user message.';
