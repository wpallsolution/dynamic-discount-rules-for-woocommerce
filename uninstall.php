<?php
/**
 * Dynamic Discount Rules for WooCommerce Uninstall
 *
 * Cleans up options and transients when plugin is deleted.
 *
 * @package WCDR
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Clean up stored plugin options
delete_option( 'wcdr_discount_rules' );
delete_option( 'wcdr_settings' );
delete_transient( 'wcdr_rules_cache' );
