UPDATE app_settings
SET setting_value = 'Generate a bright, detailed social media marketing image from this description.'
WHERE setting_key = 'openrouter_image_system_prompt'
  AND setting_value LIKE '%fetch tools%';
