UPDATE app_settings
SET setting_value = 'You research sources using the provided tools and write one unified social media post. Apply the post guidance and instructions in the user message. Return only the JSON format requested in the user message.'
WHERE setting_key IN ('openrouter_post_system_prompt', 'openrouter_system_prompt')
  AND setting_value LIKE '%facebook and linkedin keys%';
