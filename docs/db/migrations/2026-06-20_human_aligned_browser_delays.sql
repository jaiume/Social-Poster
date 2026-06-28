-- Align browser action delays with typical human point-and-click pacing (~300-900 ms).
UPDATE app_settings SET setting_value = '300' WHERE setting_key = 'browser_action_delay_ms_min';
UPDATE app_settings SET setting_value = '900' WHERE setting_key = 'browser_action_delay_ms_max';
