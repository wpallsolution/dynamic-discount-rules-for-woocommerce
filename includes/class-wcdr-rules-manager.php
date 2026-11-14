<?php
/**
 * Rules Manager Class.
 *
 * Handles CRUD operations, schema validation, and sanitization for discount rules.
 *
 * @package WCDR
 */

defined( 'ABSPATH' ) || exit;

class WCDR_Rules_Manager {

	/**
	 * Option name for storing discount rules.
	 */
	const RULES_OPTION = 'wcdr_discount_rules';

	/**
	 * Option name for storing plugin settings.
	 */
	const SETTINGS_OPTION = 'wcdr_settings';

	/**
	 * Default schema for a single discount rule.
	 *
	 * @return array
	 */
	public static function get_default_rule() {
		/**
		 * Filters the defaults of a new rule. Add-ons use it to add their own fields.
		 *
		 * @param array $defaults Default rule.
		 */
		return apply_filters( 'wcdr_default_rule', [
			'id'                     => 'rule_' . strtolower( wp_generate_password( 8, false ) ),
			'title'                  => '',
			'status'                 => 'active', // 'active' or 'inactive'
			'priority'               => 10,
			'discount_type'          => 'percentage', // 'percentage' or 'fixed'
			'discount_value'         => 10,
			'max_discount'           => 0, // 0 = unlimited cap
			'apply_mode'             => 'cart_fee', // 'cart_fee' or 'item_price'
			'show_on_catalog'        => 'yes', // 'yes' = show strikethrough price on shop & product pages
			'apply_to'               => 'all', // 'all', 'categories', 'products', 'both'
			'include_categories'     => [],
			'exclude_categories'     => [],
			'include_products'       => [],
			'exclude_products'       => [],
			'product_types'          => [ 'simple', 'variable' ], // 'all', 'simple', 'variable', etc.
			'min_cart_total'         => 0,
			'max_cart_total'         => 0,
			'user_roles'             => [], // empty = all roles
			'payment_methods'        => [], // empty = all payment methods
			'enable_payment_bonus'   => 'no', // 'yes' or 'no'
			'payment_bonus_type'     => 'percentage', // 'percentage' or 'fixed'
			'payment_bonus_value'    => 5,
			'payment_bonus_gateways'     => [], // payment gateways eligible for additional bonus discount (e.g. online, excluding COD)
			'payment_bonus_label'        => __( 'Online Payment Additional Discount', 'dynamic-discount-rules-for-woocommerce' ),
			'created_at'                 => current_time( 'mysql' ),
			'updated_at'                 => current_time( 'mysql' ),
		] );
	}

	/**
	 * Default plugin global settings.
	 *
	 * @return array
	 */
	public static function get_default_settings() {
		$defaults = [
			'enabled'                     => 'yes',
			'show_catalog_strikethrough'  => 'yes', // 'yes' = display strikethrough price immediately across shop & products
			'rule_combination'            => 'stack', // 'stack' (combine rules) or 'highest' (highest discount only)
			'tax_handling'                => 'taxable', // 'taxable' or 'non_taxable'
			'show_cart_notice'            => 'yes',
			'cart_notice_text'            => __( 'Discount applied: You saved {discount_amount} on qualifying items!', 'dynamic-discount-rules-for-woocommerce' ),
		];

		$saved = get_option( self::SETTINGS_OPTION, [] );
		if ( empty( $saved ) || ! is_array( $saved ) ) {
			update_option( self::SETTINGS_OPTION, $defaults );
			return $defaults;
		}

		return wp_parse_args( $saved, $defaults );
	}

	/**
	 * Get all discount rules.
	 *
	 * @param bool $sorted Whether to sort by priority ascending.
	 * @return array
	 */
	public static function get_rules( $sorted = true ) {
		$rules = get_option( self::RULES_OPTION, [] );
		if ( ! is_array( $rules ) ) {
			$rules = [];
		}

		if ( $sorted && ! empty( $rules ) ) {
			uasort(
				$rules,
				static function ( $a, $b ) {
					$priority_a = isset( $a['priority'] ) ? (int) $a['priority'] : 10;
					$priority_b = isset( $b['priority'] ) ? (int) $b['priority'] : 10;
					if ( $priority_a === $priority_b ) {
						return 0;
					}
					return ( $priority_a < $priority_b ) ? -1 : 1;
				}
			);
		}

		return $rules;
	}

	/**
	 * Get only active discount rules.
	 *
	 * @return array
	 */
	public static function get_active_rules() {
		$rules = self::get_rules( true );
		return array_filter(
			$rules,
			static function ( $rule ) {
				return isset( $rule['status'] ) && 'active' === $rule['status'];
			}
		);
	}

	/**
	 * Get a single rule by ID.
	 *
	 * @param string $id
	 * @return array|null
	 */
	public static function get_rule( $id ) {
		$rules = self::get_rules( false );
		return isset( $rules[ $id ] ) ? $rules[ $id ] : null;
	}

	/**
	 * Sanitize and validate rule data.
	 *
	 * @param array $raw_data
	 * @return array
	 */
	public static function sanitize_rule_data( $raw_data ) {
		$default = self::get_default_rule();
		$clean   = [];

		// ID
		$clean['id'] = ! empty( $raw_data['id'] ) ? sanitize_key( $raw_data['id'] ) : $default['id'];

		// Title
		$clean['title'] = ! empty( $raw_data['title'] ) ? sanitize_text_field( $raw_data['title'] ) : __( 'Untitled Rule', 'dynamic-discount-rules-for-woocommerce' );

		// Status
		$status = ! empty( $raw_data['status'] ) ? sanitize_key( $raw_data['status'] ) : 'active';
		$clean['status'] = in_array( $status, [ 'active', 'inactive' ], true ) ? $status : 'active';

		// Priority
		$clean['priority'] = isset( $raw_data['priority'] ) ? absint( $raw_data['priority'] ) : 10;

		// Discount Type
		$type = ! empty( $raw_data['discount_type'] ) ? sanitize_key( $raw_data['discount_type'] ) : 'percentage';
		$clean['discount_type'] = in_array( $type, [ 'percentage', 'fixed' ], true ) ? $type : 'percentage';

		// Discount Value
		$value = isset( $raw_data['discount_value'] ) ? floatval( $raw_data['discount_value'] ) : 0.0;
		if ( 'percentage' === $clean['discount_type'] ) {
			$value = max( 0.0, min( 100.0, (float) $value ) );
		} else {
			$value = max( 0.0, (float) $value );
		}
		$clean['discount_value'] = (float) $value;

		// Max Discount Cap
		$clean['max_discount'] = ! empty( $raw_data['max_discount'] ) ? max( 0.0, floatval( $raw_data['max_discount'] ) ) : 0.0;

		// Application Mode
		$mode = ! empty( $raw_data['apply_mode'] ) ? sanitize_key( $raw_data['apply_mode'] ) : 'cart_fee';
		$clean['apply_mode'] = in_array( $mode, [ 'cart_fee', 'item_price' ], true ) ? $mode : 'cart_fee';

		// Display on Shop / Product Pages (strikethrough price)
		$clean['show_on_catalog'] = ( isset( $raw_data['show_on_catalog'] ) && 'no' === $raw_data['show_on_catalog'] ) ? 'no' : 'yes';

		// Target Scope (apply_to)
		$apply_to = ! empty( $raw_data['apply_to'] ) ? sanitize_key( $raw_data['apply_to'] ) : 'all';
		$clean['apply_to'] = in_array( $apply_to, [ 'all', 'categories', 'products', 'both' ], true ) ? $apply_to : 'all';

		// Categories
		$clean['include_categories'] = ! empty( $raw_data['include_categories'] ) && is_array( $raw_data['include_categories'] )
			? array_values( array_unique( array_map( 'absint', $raw_data['include_categories'] ) ) )
			: [];

		$clean['exclude_categories'] = ! empty( $raw_data['exclude_categories'] ) && is_array( $raw_data['exclude_categories'] )
			? array_values( array_unique( array_map( 'absint', $raw_data['exclude_categories'] ) ) )
			: [];

		// Products
		$clean['include_products'] = ! empty( $raw_data['include_products'] ) && is_array( $raw_data['include_products'] )
			? array_values( array_unique( array_map( 'absint', $raw_data['include_products'] ) ) )
			: [];

		$clean['exclude_products'] = ! empty( $raw_data['exclude_products'] ) && is_array( $raw_data['exclude_products'] )
			? array_values( array_unique( array_map( 'absint', $raw_data['exclude_products'] ) ) )
			: [];

		// Product Types
		$types = ! empty( $raw_data['product_types'] ) && is_array( $raw_data['product_types'] )
			? array_map( 'sanitize_key', $raw_data['product_types'] )
			: [ 'simple', 'variable' ];
		$clean['product_types'] = array_values( array_unique( $types ) );

		// Cart Subtotal Limits
		$clean['min_cart_total'] = ! empty( $raw_data['min_cart_total'] ) ? max( 0.0, floatval( $raw_data['min_cart_total'] ) ) : 0.0;
		$clean['max_cart_total'] = ! empty( $raw_data['max_cart_total'] ) ? max( 0.0, floatval( $raw_data['max_cart_total'] ) ) : 0.0;

		// User Roles
		$roles = ! empty( $raw_data['user_roles'] ) && is_array( $raw_data['user_roles'] )
			? array_map( 'sanitize_key', $raw_data['user_roles'] )
			: [];
		$clean['user_roles'] = array_values( array_unique( $roles ) );

		// Payment Methods (Condition for primary discount)
		$methods = ! empty( $raw_data['payment_methods'] ) && is_array( $raw_data['payment_methods'] )
			? array_map( 'sanitize_text_field', $raw_data['payment_methods'] )
			: [];
		$clean['payment_methods'] = array_values( array_unique( $methods ) );

		// Additional Payment Bonus Discount (e.g. +5% for Online Payment, excluded on COD)
		$clean['enable_payment_bonus'] = ( ! empty( $raw_data['enable_payment_bonus'] ) && in_array( $raw_data['enable_payment_bonus'], [ 'yes', '1', 1, true ], true ) ) ? 'yes' : 'no';

		$bonus_type = ! empty( $raw_data['payment_bonus_type'] ) ? sanitize_key( $raw_data['payment_bonus_type'] ) : 'percentage';
		$clean['payment_bonus_type'] = in_array( $bonus_type, [ 'percentage', 'fixed' ], true ) ? $bonus_type : 'percentage';

		$bonus_value = isset( $raw_data['payment_bonus_value'] ) ? floatval( $raw_data['payment_bonus_value'] ) : 0.0;
		if ( 'percentage' === $clean['payment_bonus_type'] ) {
			$bonus_value = max( 0.0, min( 100.0, (float) $bonus_value ) );
		} else {
			$bonus_value = max( 0.0, (float) $bonus_value );
		}
		$clean['payment_bonus_value'] = (float) $bonus_value;

		$bonus_gateways = ! empty( $raw_data['payment_bonus_gateways'] ) && is_array( $raw_data['payment_bonus_gateways'] )
			? array_map( 'sanitize_text_field', $raw_data['payment_bonus_gateways'] )
			: [];
		$clean['payment_bonus_gateways'] = array_values( array_unique( $bonus_gateways ) );

		$clean['payment_bonus_label'] = ! empty( $raw_data['payment_bonus_label'] )
			? sanitize_text_field( $raw_data['payment_bonus_label'] )
			: __( 'Online Payment Additional Discount', 'dynamic-discount-rules-for-woocommerce' );

		// Timestamps
		$clean['created_at'] = ! empty( $raw_data['created_at'] ) ? sanitize_text_field( $raw_data['created_at'] ) : current_time( 'mysql' );
		$clean['updated_at'] = current_time( 'mysql' );

		/**
		 * Filters a sanitized rule before it is saved. Add-ons use it to sanitize and keep their own fields.
		 *
		 * @param array $clean    Sanitized rule.
		 * @param array $raw_data Submitted data.
		 */
		return apply_filters( 'wcdr_sanitize_rule', $clean, $raw_data );
	}

	/**
	 * Maximum number of rules in the free version.
	 *
	 * @return int
	 */
	public static function rule_limit() {
		return max( 1, (int) apply_filters( 'wcdr_free_rule_limit', 10 ) );
	}

	/**
	 * Whether the given rule count has reached the free limit.
	 *
	 * @param int|null $count Current number of rules; counted when omitted.
	 * @return bool
	 */
	public static function limit_reached( $count = null ) {
		if ( null === $count ) {
			$count = count( self::get_rules( false ) );
		}
		return $count >= self::rule_limit();
	}

	/**
	 * Where the upgrade button points.
	 *
	 * @return string
	 */
	public static function upgrade_url() {
		return esc_url_raw( apply_filters( 'wcdr_upgrade_url', 'https://profiles.wordpress.org/hplugin/' ) );
	}

	/**
	 * Save or update a discount rule.
	 *
	 * @param array $rule_data
	 * @return string|WP_Error Rule ID on success, WP_Error on failure.
	 */
	public static function save_rule( $rule_data ) {
		if ( empty( $rule_data['title'] ) ) {
			return new WP_Error( 'missing_title', __( 'Rule title is required.', 'dynamic-discount-rules-for-woocommerce' ) );
		}

		$sanitized = self::sanitize_rule_data( $rule_data );
		$rules     = self::get_rules( false );

		// sanitize_key() lowercases IDs; match rules saved earlier with mixed-case IDs.
		if ( ! isset( $rules[ $sanitized['id'] ] ) ) {
			foreach ( array_keys( $rules ) as $existing_id ) {
				if ( 0 === strcasecmp( (string) $existing_id, $sanitized['id'] ) ) {
					$sanitized['id'] = (string) $existing_id;
					break;
				}
			}
		}

		if ( ! isset( $rules[ $sanitized['id'] ] ) && self::limit_reached( count( $rules ) ) ) {
			return new WP_Error(
				'rule_limit',
				/* translators: %d: maximum number of rules in the free version */
				sprintf( __( 'The free version supports up to %d rules. Upgrade to Pro for unlimited rules.', 'dynamic-discount-rules-for-woocommerce' ), self::rule_limit() )
			);
		}

		$rules[ $sanitized['id'] ] = $sanitized;
		update_option( self::RULES_OPTION, $rules );
		WCDR_I18n::register_rule( $sanitized );

		return $sanitized['id'];
	}

	/**
	 * Delete a rule by ID.
	 *
	 * @param string $id
	 * @return bool
	 */
	public static function delete_rule( $id ) {
		$rules = self::get_rules( false );
		if ( isset( $rules[ $id ] ) ) {
			unset( $rules[ $id ] );
			update_option( self::RULES_OPTION, $rules );
			return true;
		}
		return false;
	}

	/**
	 * Toggle active/inactive status of a rule.
	 *
	 * @param string $id
	 * @return string New status ('active'|'inactive') or false on failure.
	 */
	public static function toggle_rule_status( $id ) {
		$rules = self::get_rules( false );
		if ( isset( $rules[ $id ] ) ) {
			$current_status = $rules[ $id ]['status'] ?? 'active';
			$new_status     = ( 'active' === $current_status ) ? 'inactive' : 'active';
			$rules[ $id ]['status']     = $new_status;
			$rules[ $id ]['updated_at'] = current_time( 'mysql' );
			update_option( self::RULES_OPTION, $rules );
			return $new_status;
		}
		return false;
	}

	/**
	 * Duplicate an existing rule.
	 *
	 * @param string $id
	 * @return string|false|WP_Error New rule ID, false on failure, WP_Error when the free limit is reached.
	 */
	public static function duplicate_rule( $id ) {
		$rule = self::get_rule( $id );
		if ( ! $rule ) {
			return false;
		}

		$clone               = $rule;
		$clone['id']         = 'rule_' . strtolower( wp_generate_password( 8, false ) );
		/* translators: %s: original rule title */
		$clone['title']      = sprintf( __( '%s (Copy)', 'dynamic-discount-rules-for-woocommerce' ), $rule['title'] );
		$clone['status']     = 'inactive'; // Duplicate starts inactive for safety
		$clone['created_at'] = current_time( 'mysql' );
		$clone['updated_at'] = current_time( 'mysql' );

		return self::save_rule( $clone );
	}

	/**
	 * Save global plugin settings.
	 *
	 * @param array $raw_settings
	 * @return array Sanitized settings
	 */
	public static function save_settings( $raw_settings ) {
		$clean = [
			'enabled'                    => ( isset( $raw_settings['enabled'] ) && 'yes' === $raw_settings['enabled'] ) ? 'yes' : 'no',
			'show_catalog_strikethrough' => ( isset( $raw_settings['show_catalog_strikethrough'] ) && 'yes' === $raw_settings['show_catalog_strikethrough'] ) ? 'yes' : 'no',
			'rule_combination'           => ( isset( $raw_settings['rule_combination'] ) && 'highest' === $raw_settings['rule_combination'] ) ? 'highest' : 'stack',
			'tax_handling'               => ( isset( $raw_settings['tax_handling'] ) && 'non_taxable' === $raw_settings['tax_handling'] ) ? 'non_taxable' : 'taxable',
			'show_cart_notice'           => ( isset( $raw_settings['show_cart_notice'] ) && 'yes' === $raw_settings['show_cart_notice'] ) ? 'yes' : 'no',
			'cart_notice_text'           => ! empty( $raw_settings['cart_notice_text'] ) ? sanitize_text_field( $raw_settings['cart_notice_text'] ) : '',
		];

		update_option( self::SETTINGS_OPTION, $clean );
		WCDR_I18n::register( 'cart_notice_text', $clean['cart_notice_text'] );
		return $clean;
	}

	/**
	 * Get all product categories formatted for select dropdown.
	 *
	 * @return array
	 */
	public static function get_all_categories() {
		$terms = get_terms( [
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		] );

		$list = [];
		if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$list[ $term->term_id ] = $term->name . ' (' . $term->count . ')';
			}
		}
		return $list;
	}

	/**
	 * Get all WooCommerce payment gateways.
	 *
	 * @return array
	 */
	public static function get_all_payment_gateways() {
		$gateways = [];
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			$available = WC()->payment_gateways()->payment_gateways();
			if ( ! empty( $available ) ) {
				foreach ( $available as $gateway ) {
					$title = ! empty( $gateway->get_title() ) ? $gateway->get_title() : $gateway->id;
					$gateways[ $gateway->id ] = $title . ' [' . $gateway->id . ']';
				}
			}
		}

		// Fallback defaults if gateways not yet loaded or initialized
		if ( empty( $gateways ) ) {
			$gateways = [
				'bacs'   => __( 'Direct Bank Transfer [bacs]', 'dynamic-discount-rules-for-woocommerce' ),
				'cheque' => __( 'Check payments [cheque]', 'dynamic-discount-rules-for-woocommerce' ),
				'cod'    => __( 'Cash on delivery [cod]', 'dynamic-discount-rules-for-woocommerce' ),
				'paypal' => __( 'PayPal [paypal]', 'dynamic-discount-rules-for-woocommerce' ),
				'stripe' => __( 'Stripe [stripe]', 'dynamic-discount-rules-for-woocommerce' ),
			];
		}

		return $gateways;
	}

	/**
	 * Get all editable WordPress user roles + Guest option.
	 *
	 * @return array
	 */
	public static function get_all_user_roles() {
		$roles = [
			'guest' => __( 'Guest / Not Logged In', 'dynamic-discount-rules-for-woocommerce' ),
		];

		if ( function_exists( 'wp_roles' ) ) {
			$wp_roles = wp_roles();
			if ( ! empty( $wp_roles->roles ) ) {
				foreach ( $wp_roles->roles as $key => $role ) {
					$roles[ $key ] = translate_user_role( $role['name'] );
				}
			}
		}

		return $roles;
	}

	/**
	 * Get supported product types.
	 *
	 * @return array
	 */
	public static function get_all_product_types() {
		return [
			'simple'   => __( 'Simple Product', 'dynamic-discount-rules-for-woocommerce' ),
			'variable' => __( 'Variable / Variation Product', 'dynamic-discount-rules-for-woocommerce' ),
			'grouped'  => __( 'Grouped Product', 'dynamic-discount-rules-for-woocommerce' ),
			'external' => __( 'External / Affiliate Product', 'dynamic-discount-rules-for-woocommerce' ),
		];
	}
}
