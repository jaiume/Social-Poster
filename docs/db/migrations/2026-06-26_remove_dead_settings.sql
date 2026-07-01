DELETE FROM app_settings WHERE setting_key IN (
    'cron_posting_enabled',
    'browser_state_machine_mode',
    'browser_state_machine_shadow_mode',
    'browser_verify_allow_weak',
    'browser_headless',
    'posting_max_retries_per_day',
    'browser_action_delay_ms_min',
    'browser_action_delay_ms_max',
    'openrouter_system_prompt',
    'browser_script_timeout_ms'
);
