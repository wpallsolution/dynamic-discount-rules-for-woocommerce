<?php
/**
 * AJAX Handlers Class.
 *
 * Handles product search for Select2, inline rule toggling, cloning, and deletion.
 *
 * @package WCDR
 */

defined( 'ABSPATH' ) || exit;

class WCDR_Ajax {

	/**
	 * Singleton instance.
	 *
	 * @var WCDR_Ajax|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return WCDR_Ajax
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
		add_action( 'wp_ajax_wcdr_search_products', [ $this, 'ajax_search_products' ] );
		add_action( 'wp_ajax_wcdr_toggle_rule', [ $this, 'ajax_toggle_rule' ] );
		add_action( 'wp_ajax_wcdr_duplicate_rule', [ $this, 'ajax_duplicate_rule' ] );
		add_action( 'wp_ajax_wcdr_delete_rule', [ $this, 'ajax_delete_rule' ] );
	}

	/**
	 * Verify permissions and nonce for admin AJAX requests.
	 */
	private function verify_security() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'dynamic-discount-rules-for-woocommerce' ) ], 403 );
		}
		check_ajax_referer( 'wcdr_admin_nonce', 'security' );
	}

	/**
	 * AJAX product search for Select2 multiselect.
	 */
	public function ajax_search_products() {
		$this->verify_security();

		$term = ! empty( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verify_security() above checks the nonce.
		if ( empty( $term ) ) {
			wp_send_json_success( [] );
		}

		$products = wc_get_products( [
			's'       => $term,
			'limit'   => 30,
			'status'  => 'publish',
			'orderby' => 'title',
			'order'   => 'ASC',
		] );

		$results = [];
		if ( ! empty( $products ) ) {
			foreach ( $products as $prod ) {
				$sku   = $prod->get_sku() ? ' (SKU: ' . $prod->get_sku() . ')' : '';
				$price = $prod->get_price() ? ' - ' . wc_price( $prod->get_price() ) : '';

				$results[] = [
					'id'   => $prod->get_id(),
					'text' => '#' . $prod->get_id() . ' ' . $prod->get_name() . $sku . ' [' . $prod->get_type() . ']' . wp_strip_all_tags( $price ),
				];
			}
		}

		wp_send_json_success( $results );
	}

	/**
	 * AJAX toggle rule status (active <-> inactive).
	 */
	public function ajax_toggle_rule() {
		$this->verify_security();

		$rule_id = ! empty( $_POST['rule_id'] ) ? sanitize_key( wp_unslash( $_POST['rule_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_security() above checks the nonce.
		if ( empty( $rule_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid rule ID.', 'dynamic-discount-rules-for-woocommerce' ) ] );
		}

		$new_status = WCDR_Rules_Manager::toggle_rule_status( $rule_id );
		if ( false !== $new_status ) {
			wp_send_json_success( [
				'status'  => $new_status,
				'message' => 'active' === $new_status ? __( 'Rule activated', 'dynamic-discount-rules-for-woocommerce' ) : __( 'Rule deactivated', 'dynamic-discount-rules-for-woocommerce' ),
			] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Rule not found.', 'dynamic-discount-rules-for-woocommerce' ) ] );
		}
	}

	/**
	 * AJAX duplicate rule.
	 */
	public function ajax_duplicate_rule() {
		$this->verify_security();

		$rule_id = ! empty( $_POST['rule_id'] ) ? sanitize_key( wp_unslash( $_POST['rule_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_security() above checks the nonce.
		if ( empty( $rule_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid rule ID.', 'dynamic-discount-rules-for-woocommerce' ) ] );
		}

		$new_id = WCDR_Rules_Manager::duplicate_rule( $rule_id );
		if ( is_wp_error( $new_id ) ) {
			wp_send_json_error( [ 'message' => $new_id->get_error_message() ] );
		}
		if ( $new_id ) {
			wp_send_json_success( [
				'new_id'  => $new_id,
				'message' => __( 'Rule duplicated successfully.', 'dynamic-discount-rules-for-woocommerce' ),
			] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Could not duplicate rule.', 'dynamic-discount-rules-for-woocommerce' ) ] );
		}
	}

	/**
	 * AJAX delete rule.
	 */
	public function ajax_delete_rule() {
		$this->verify_security();

		$rule_id = ! empty( $_POST['rule_id'] ) ? sanitize_key( wp_unslash( $_POST['rule_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_security() above checks the nonce.
		if ( empty( $rule_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid rule ID.', 'dynamic-discount-rules-for-woocommerce' ) ] );
		}

		$deleted = WCDR_Rules_Manager::delete_rule( $rule_id );
		if ( $deleted ) {
			wp_send_json_success( [ 'message' => __( 'Rule deleted successfully.', 'dynamic-discount-rules-for-woocommerce' ) ] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Could not delete rule.', 'dynamic-discount-rules-for-woocommerce' ) ] );
		}
	}
}
