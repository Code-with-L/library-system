<?php
/**
 * Production configuration template.
 *
 * Copy to config/config.local.php on the hosting account and fill in the
 * values from the hosting control panel. This file is git-ignored and must
 * remain OUTSIDE the public document root (htdocs).
 *
 * NEVER commit real credentials. Environment variables still take precedence,
 * which preserves the Docker development setup.
 */
return [
    // Application identity and HTTPS URL (used for base path/canonical URLs).
    'APP_ENV'                   => 'production',
    'APP_DEBUG'                 => 'false',
    'APP_URL'                   => 'https://your-domain.example',
    'APP_BASE_PATH'             => '',

    // Exact MySQL host, database, user and password from the hosting control
    // panel. Do NOT use "localhost" on InfinityFree.
    'DB_HOST'                   => 'sqlXXX.infinityfree.com',
    'DB_PORT'                   => '3306',
    'DB_NAME'                   => 'if0_XXXXXX_library',
    'DB_USER'                   => 'if0_XXXXXX',
    'DB_PASS'                   => 'REPLACE_WITH_ACCOUNT_PASSWORD',
    'DB_CHARSET'                => 'utf8mb4',

    // Long random string used once by setup-admin.php. Clear after setup.
    'SETUP_TOKEN'               => '',

    // Proxy-aware HTTPS detection: enable only on hosts that set
    // X-Forwarded-Proto (InfinityFree terminates TLS upstream).
    'APP_TRUST_PROXY_HEADERS'   => 'true',
    'SESSION_COOKIE_SECURE'     => '1',

    // Public root path. Leave empty for the standard local layout; set the
    // absolute htdocs path when public/ is deployed to InfinityFree htdocs.
    'APP_PUBLIC_PATH'           => '',

    // HSTS: enable only after HTTPS has been verified on the live domain.
    'HSTS_MAX_AGE'              => '0',

    // Student email verification. Leave off until email delivery is confirmed.
    // When on, students must verify before they can request books.
    'EMAIL_VERIFICATION_ENABLED' => 'false',
    'EMAIL_FROM_ADDRESS'        => '',

    // Password reset by email. Requires working email delivery first.
    'PASSWORD_RESET_ENABLED'    => 'false',

    // Multi-factor authentication (TOTP authenticator apps).
    'MFA_ENABLED'               => 'false',
];
