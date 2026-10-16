<?php

// Quissly for PrestaShop - en texts of the PrestaShop module itself: its own, and the CS-Cart
// add-on's texts that named CS-Cart, reworded (texts/en.php keeps CS-Cart's).
return [
    'quissly_search.dash_gate_blocked' => 'The last full sync finished without Quissly confirming any product, so search stays on native PrestaShop search. Check the sync log for the cause, then sync again.',
    'quissly_search.dash_open_settings' => 'Module settings',
    'quissly_search.dash_search_inactive' => 'Storefront search uses native PrestaShop search, because:',
    'quissly_search.feature_not_built' => 'not built into the PrestaShop module yet: saved, but has no effect until it ships',
    'quissly_search.no' => 'No',
    'quissly_search.not_configured' => 'Not configured in config/defines_custom.inc.php',
    'quissly_search.origin_native' => 'Results by PrestaShop search',
    'quissly_search.panel_missing_account' => 'The panel can\'t sign you in automatically: config/defines_custom.inc.php has no QUISSLY_PROJECT_ID or QUISSLY_ACCOUNT_EMAIL for this store. Open it in a new tab and sign in with your Quissly account.',
    'quissly_search.panel_platform_unsupported' => 'Quissly can\'t sign PrestaShop stores in to the panel automatically yet. Open it in a new tab and sign in with your Quissly account.',
    'quissly_search.reason_credentials_not_configured' => 'Credentials are not configured in config/defines_custom.inc.php',
    'quissly_search.reason_initial_sync_pending' => 'The first catalog sync hasn\'t been confirmed by Quissly yet, so the store uses native PrestaShop search until it is.',
    'quissly_search.save' => 'Save',
    'quissly_search.setting_tooltip.enable_quick' => 'Product suggestions as the shopper types. Saved now; it takes effect once the feature ships in this module.',
    'quissly_search.setting_tooltip.quick_style' => 'A compact list of names, or larger cards with images and prices. Saved now; it takes effect once Quick Recommendations ships in this module.',
    'quissly_search.setting_tooltip.search_interception' => 'When off, the storefront uses native PrestaShop search. Credentials and catalog sync are unaffected.',
    'quissly_search.setting_tooltip.update_check_every' => 'How often the module looks for a new Quissly version and installs it. Never: no automatic updates.',
    'quissly_search.setup_connected_config' => 'This store is connected to Quissly with credentials set in config/defines_custom.inc.php (environment: [env]). One-click Connect is not needed.',
    'quissly_search.setup_step_key' => 'The module generates the store\'s signing key and registers it with Quissly. The keys are stored encrypted; you never need to copy or paste one.',
    'quissly_search.setup_undecryptable' => 'This store was connected to Quissly, but its stored credentials can no longer be read: the shop\'s cookie_key in app/config/parameters.php has changed since. Restore the previous cookie_key. Connecting again would create a second Quissly account, so it is not offered here - if the old key is lost, contact Quissly support.',
    'quissly_search.su_live_safety_text' => 'If Quissly ever cannot answer, PrestaShop\'s search does.',
    'quissly_search.test_connection_auth_fail' => 'Quissly authorization failed (HTTP [status]). Check the bearer token, private key, and environment in config/defines_custom.inc.php.',
    'quissly_search.dash_cron_missing' => 'Automatic sync is not set up on the server. Product changes are queued and sent a few at a time whenever someone opens a page of this admin, or at once when you press a sync button above. To send them on time even when nobody is in the admin, add this line to the server\'s crontab or your hosting panel\'s cron jobs (every 5 minutes):',
];
