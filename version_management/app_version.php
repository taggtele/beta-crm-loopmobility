<?php
/**
 * Application release & branding constants (single source of truth).
 *
 * Default APP_VERSION / APP_BUILD / APP_RELEASE_DATE for new installs (no release.json yet).
 * Live releases are stored in release.json — Admin UI does not modify this file.
 * APP_NAME stays in config/db.php bootstrap defaults unless overridden elsewhere.
 * Deployment tier badge uses APP_ENV from .env via app_environment_display() — not defined here.
 */

define('APP_VERSION', '1.0.0');
define('APP_BUILD', '20260419');
define('APP_RELEASE_DATE', '2026-04-19');
define('APP_COMPANY', 'Loop Mobility');
/** Subtitle under the logo in the sidebar (keep short). */
define('APP_PRODUCT_TAGLINE', 'Support Desk');
/**
 * Optional footer copyright line; empty string = auto "© {year} {APP_COMPANY}" when company set.
 */
define('APP_COPYRIGHT_NOTICE', '');
define('APP_SUPPORT_EMAIL', 'support@taggteleservices.com');
define('APP_WEBSITE', 'https://taggteleservices.com');

/**
 * Release Notes Summary (git defaults — live notes in release.json)
 *
 * v2.0.0 - Release management UI, email logs & ticket reply workflow
 * v1.0.0 - Initial production release
 */