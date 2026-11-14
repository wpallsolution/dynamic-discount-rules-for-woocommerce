<?php
/**
 * Plugin Name: Dynamic Discount Rules for WooCommerce
 * Description: Advanced, reliable discount rules for WooCommerce: live catalog strikethrough prices, category discounts, product discounts, exclusions, cart min/max totals, product types (simple/variable), online payment bonuses, payment methods, and user roles.
 * Version:     1.3.0
 * Author:      hplugin
 * Author URI:  https://profiles.wordpress.org/hplugin/
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dynamic-discount-rules-for-woocommerce
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 5.0
 * Tested up to: 7.1
 * WC tested up to: 11.2
 *
 * @package WCDR
 */

defined( 'ABSPATH' ) || exit;

// Plugin constants
define( 'WCDR_VERSION', '1.3.0' );
define( 'WCDR_PLUGIN_FILE', __FILE__ );
define( 'WCDR_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'WCDR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WCDR_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Declare WooCommerce High-Performance Order Storage (HPOS) and Blocks compatibility.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WCDR_PLUGIN_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WCDR_PLUGIN_FILE, true );
		}
	}
);

/**
 * Load plugin dependencies.
 */
require_once WCDR_PLUGIN_PATH . 'includes/class-wcdr-i18n.php';
require_once WCDR_PLUGIN_PATH . 'includes/class-wcdr-rules-manager.php';
require_once WCDR_PLUGIN_PATH . 'includes/class-wcdr-engine.php';
require_once WCDR_PLUGIN_PATH . 'includes/class-wcdr-frontend.php';
require_once WCDR_PLUGIN_PATH . 'includes/class-wcdr-ajax.php';

if ( is_admin() ) {
	require_once WCDR_PLUGIN_PATH . 'includes/class-wcdr-admin.php';
}

/**
 * Main plugin bootstrap class.
 */
final class WC_Discount_Rules {

	/**
	 * Singleton instance.
	 *
	 * @var WC_Discount_Rules|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return WC_Discount_Rules
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'plugins_loaded', [ $this, 'init_plugin' ] );
		register_activation_hook( WCDR_PLUGIN_FILE, [ $this, 'activate' ] );
		register_deactivation_hook( WCDR_PLUGIN_FILE, [ $this, 'deactivate' ] );
	}

	/**
	 * Initialize plugin when plugins are loaded.
	 */
	public function init_plugin() {
		// Verify WooCommerce is active
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', [ $this, 'woocommerce_missing_notice' ] );
			return;
		}

		// WPML: translatable texts
		WCDR_I18n::init();

		// Initialize frontend hooks
		WCDR_Frontend::instance();

		// Initialize AJAX handlers
		WCDR_Ajax::instance();

		// Initialize admin UI
		if ( is_admin() ) {
			WCDR_Admin::instance();
		}

	}

	/**
	 * Admin notice if WooCommerce is inactive.
	 */
	public function woocommerce_missing_notice() {
		?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'Dynamic Discount Rules for WooCommerce', 'dynamic-discount-rules-for-woocommerce' ); ?>:</strong>
				<?php esc_html_e( 'WooCommerce is required for this plugin to work. Please install and activate WooCommerce.', 'dynamic-discount-rules-for-woocommerce' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Activation hook.
	 */
	public function activate() {
		// Set default settings if not already set
		$settings = get_option( 'wcdr_settings' );
		if ( false === $settings ) {
			WCDR_Rules_Manager::get_default_settings();
		}

		// Set default rules option if not exists
		$rules = get_option( 'wcdr_discount_rules' );
		if ( false === $rules ) {
			update_option( 'wcdr_discount_rules', [] );
		}
	}

	/**
	 * Deactivation hook.
	 */
	public function deactivate() {
		// Clean up any temporary transients if needed
		delete_transient( 'wcdr_rules_cache' );
	}
}

// Bootstrap the plugin
WC_Discount_Rules::instance();
