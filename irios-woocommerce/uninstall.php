<?php
/**
 * Uninstall: deletes the settings, the product webhooks to the sync service (SyncWebhooks), the new-version notice
 * data, the consent withdrawals table (Revocations) and its daily purge. The orders' `_irios_consent` and `_irios_uid`
 * metas and the customers' last answer (`_irios_consent`, `_irios_consent_at` and `_irios_revoked` user meta) are
 * kept: they are part of the order and customer history, and a customer who withdrew consent must not count as
 * consenting if the plugin is installed again.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'woocommerce_irios_settings' );
// New-version notice (Updates): last release checked and notices dismissed by each user
delete_transient( 'irios_wc_latest_release' );
delete_metadata( 'user', 0, 'irios_wc_dismissed_release', '', true );
// Consent withdrawals waiting for the sync service, the table's version and its daily purge
require_once __DIR__ . '/src/Revocations.php';
\Irios\WooCommerce\Revocations::uninstall();
// The product webhooks to the IRIOS Media sync service that the plugin created (SyncWebhooks)
require_once __DIR__ . '/src/SyncWebhooks.php';
\Irios\WooCommerce\SyncWebhooks::remove();
