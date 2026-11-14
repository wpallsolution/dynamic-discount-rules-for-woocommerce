<?php
/**
 * Discount Rules Engine Class.
 *
 * Evaluates rules against cart contents, customer roles, payment methods, and subtotals.
 *
 * @package WCDR
 */

defined( 'ABSPATH' ) || exit;

class WCDR_Engine {

	/**
	 * Cache for product categories to avoid redundant DB queries.
	 *
	 * @var array
	 */
	private static $category_cache = [];

	/**
	 * Get all applicable discount rules for the current cart state.
	 *
	 * @param WC_Cart     $cart
	 * @param string|null $override_payment_method Optional payment method to evaluate.
	 * @return array Array of applicable rules with calculated discounts.
	 */
	public static function get_applicable_rules( $cart, $override_payment_method = null ) {
		if ( ! $cart || $cart->is_empty() ) {
			return [];
		}

		$settings = WCDR_Rules_Manager::get_default_settings();
		if ( 'yes' !== ( $settings['enabled'] ?? 'yes' ) ) {
			return [];
		}

		$active_rules = WCDR_Rules_Manager::get_active_rules();
		if ( empty( $active_rules ) ) {
			return [];
		}

		// Calculate raw cart subtotal based on line item subtotals (pre-discount)
		$cart_subtotal = self::get_raw_cart_subtotal( $cart );
		if ( $cart_subtotal <= 0 ) {
			return [];
		}

		// Determine customer roles
		$customer_roles = self::get_current_user_roles();

		// Determine chosen payment method
		$chosen_payment = ! empty( $override_payment_method )
			? sanitize_text_field( $override_payment_method )
			: self::get_chosen_payment_method();

		$applicable_rules = [];

		foreach ( $active_rules as $rule ) {
			// 1. Verify User Role condition
			if ( ! self::is_role_eligible( $rule, $customer_roles ) ) {
				continue;
			}

			// 2. Verify Payment Method condition
			if ( ! self::is_payment_method_eligible( $rule, $chosen_payment ) ) {
				continue;
			}

			// 3. Verify Cart Min/Max Subtotal condition
			if ( ! self::is_cart_subtotal_eligible( $rule, $cart_subtotal ) ) {
				continue;
			}

			// Extra conditions from add-ons (schedule, first order, ...).
			if ( ! apply_filters( 'wcdr_rule_applies', true, $rule, [ 'cart' => $cart ] ) ) {
				continue;
			}

			// 4. Evaluate Item-Level Eligibility and calculate discount
			$calculation = self::calculate_rule_discount( $rule, $cart );
			if ( $calculation['discount_amount'] > 0 && ! empty( $calculation['eligible_items'] ) ) {
				$applicable_rules[] = array_merge(
					$rule,
					[
						'calculated_discount' => $calculation['discount_amount'],
						'qualifying_subtotal' => $calculation['qualifying_subtotal'],
						'eligible_item_keys'  => $calculation['eligible_items'],
					]
				);
			}

			// 5. Evaluate Payment Method Bonus Discount (e.g. +5% for Online Payment, 0% for COD)
			if ( ! empty( $rule['enable_payment_bonus'] ) && 'yes' === $rule['enable_payment_bonus'] ) {
				$bonus_gateways = $rule['payment_bonus_gateways'] ?? [];
				$bonus_value    = floatval( $rule['payment_bonus_value'] ?? 0 );

				if ( ! empty( $chosen_payment ) && $bonus_value > 0 ) {
					$gateway_matched = false;
					if ( ! empty( $bonus_gateways ) ) {
						if ( in_array( $chosen_payment, (array) $bonus_gateways, true ) ) {
							$gateway_matched = true;
						}
					} else {
					// Default: every gateway except the offline ones (money is not received at checkout).
					$offline = (array) apply_filters( 'wcdr_offline_gateways', [ 'cod', 'bacs', 'cheque' ] );
					if ( ! in_array( strtolower( $chosen_payment ), $offline, true ) ) {
					$gateway_matched = true;
					}
					}

					if ( $gateway_matched ) {
						// CRITICAL: Payment bonus strictly belongs to items qualifying for this rule.
						// If qualifying_subtotal is 0 or empty eligible_items (e.g. all items in cart are in excluded categories like Fabric),
						// NEVER apply any bonus! Under NO circumstances fall back to $cart_subtotal!
						$bonus_calc_subtotal = floatval( $calculation['qualifying_net'] ?? 0 );
						if ( $bonus_calc_subtotal <= 0 || empty( $calculation['eligible_items'] ) ) {
							continue;
						}
						$bonus_type   = $rule['payment_bonus_type'] ?? 'percentage';
						$bonus_amount = 0;

						if ( 'percentage' === $bonus_type ) {
							$bonus_pct    = max( 0, min( 100, $bonus_value ) );
							$bonus_amount = ( $bonus_calc_subtotal * $bonus_pct ) / 100;
						} else {
							$bonus_amount = min( $bonus_value, $bonus_calc_subtotal );
						}

						$decimals     = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
						$bonus_amount = round( $bonus_amount, $decimals );

						if ( $bonus_amount > 0 ) {
							$bonus_label = ! empty( $rule['payment_bonus_label'] )
							? WCDR_I18n::bonus_label( $rule, $rule['payment_bonus_label'] )
							: __( 'Online Payment Additional Discount', 'dynamic-discount-rules-for-woocommerce' );

							if ( 'percentage' === $bonus_type ) {
								$bonus_label .= ' (' . floatval( $bonus_value ) . '%)';
							}

							$applicable_rules[] = [
								'id'                  => $rule['id'] . '_bonus',
								'title'               => $bonus_label,
								'status'              => 'active',
								'apply_mode'          => 'cart_fee',
								'discount_type'       => $bonus_type,
								'discount_value'      => $bonus_value,
								'calculated_discount' => $bonus_amount,
								'qualifying_subtotal' => $bonus_calc_subtotal,
								'eligible_item_keys'  => $calculation['eligible_items'] ?? [],
								'is_payment_bonus'    => true,
							];
						}
					}
				}
			}
		}

		if ( empty( $applicable_rules ) ) {
			return [];
		}

		// 5. Apply combination logic (Highest discount vs Stack all)
		$combination_mode = $settings['rule_combination'] ?? 'stack';

		if ( 'highest' === $combination_mode ) {
			// Find rule with highest calculated discount
			usort(
				$applicable_rules,
				static function ( $a, $b ) {
					if ( $a['calculated_discount'] === $b['calculated_discount'] ) {
						return 0;
					}
					return ( $a['calculated_discount'] > $b['calculated_discount'] ) ? -1 : 1;
				}
			);
			return [ $applicable_rules[0] ];
		}

		// Stacking mode: Ensure cumulative discount never exceeds available cart margin
		$cumulative_discount = 0;
		$final_rules         = [];
		$coupon_discount     = ( is_a( $cart, 'WC_Cart' ) && is_callable( [ $cart, 'get_discount_total' ] ) ) ? floatval( $cart->get_discount_total() ) : 0.0;
		$net_cart_limit      = max( 0.0, $cart_subtotal - $coupon_discount );

		foreach ( $applicable_rules as $app_rule ) {
			$available_margin = max( 0.0, $net_cart_limit - $cumulative_discount );
			if ( $available_margin <= 0 ) {
				break;
			}

			// Cap individual rule to available cart margin
			if ( $app_rule['calculated_discount'] > $available_margin ) {
				$app_rule['calculated_discount'] = $available_margin;
			}

			if ( $app_rule['calculated_discount'] > 0 ) {
				$cumulative_discount += $app_rule['calculated_discount'];
				$final_rules[]        = $app_rule;
			}
		}

		return $final_rules;
	}

	/**
	 * Price of one unit before any discount of this plugin.
	 *
	 * Always the product's regular price (variations fall back to their parent). It never reads
	 * the line subtotal of an earlier calculation, because that already contains this plugin's own
	 * discount and made the rules switch on and off on every recalculation.
	 *
	 * @param array $cart_item Cart item.
	 * @return float
	 */
	public static function get_item_baseline_price( $cart_item ) {
		$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
		if ( ! is_a( $product, 'WC_Product' ) ) {
			return 0.0;
		}

		$price = floatval( $product->get_regular_price() );

		if ( $price <= 0 && $product->is_type( 'variation' ) && $product->get_parent_id() ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent ) {
				$price = floatval( $parent->get_regular_price() );
			}
		}

		if ( $price <= 0 ) {
			$price = isset( $cart_item['wcdr_original_price'] ) ? floatval( $cart_item['wcdr_original_price'] ) : floatval( $product->get_price() );
		}

		return $price;
	}

	/**
	 * Cart subtotal before this plugin's discounts. Used for the minimum / maximum cart total rules.
	 *
	 * @param WC_Cart $cart
	 * @return float
	 */
	public static function get_raw_cart_subtotal( $cart ) {
		$subtotal = 0;
		foreach ( $cart->get_cart() as $cart_item ) {
			$subtotal += self::get_item_baseline_price( $cart_item ) * absint( $cart_item['quantity'] ?? 1 );
		}
		return (float) $subtotal;
	}

	/**
	 * Get current user roles, including 'guest' if not logged in.
	 *
	 * @return array
	 */
	public static function get_current_user_roles() {
		if ( ! is_user_logged_in() ) {
			return [ 'guest' ];
		}

		$user = wp_get_current_user();
		return ! empty( $user->roles ) ? (array) $user->roles : [ 'customer' ];
	}

	/**
	 * Detect chosen payment method across checkout stages.
	 *
	 * @return string
	 */
	public static function get_chosen_payment_method() {
		// 1. Direct POST from AJAX update_order_review
		if ( ! empty( $_POST['payment_method'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout request; this only reads the chosen gateway
			return sanitize_text_field( wp_unslash( $_POST['payment_method'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		// 2. Serialized form post_data in checkout AJAX
		if ( ! empty( $_POST['post_data'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout request; this only reads the chosen gateway
			$post_data = [];
			wp_parse_str( sanitize_text_field( wp_unslash( $_POST['post_data'] ) ), $post_data ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! empty( $post_data['payment_method'] ) ) {
				return sanitize_text_field( $post_data['payment_method'] );
			}
		}

		// 3. WooCommerce session
		if ( function_exists( 'WC' ) && WC()->session ) {
			$session_payment = WC()->session->get( 'chosen_payment_method' );
			if ( ! empty( $session_payment ) ) {
				return sanitize_text_field( $session_payment );
			}
		}

		return '';
	}

	/**
	 * Check if rule qualifies for user's roles.
	 *
	 * @param array $rule
	 * @param array $user_roles
	 * @return bool
	 */
	public static function is_role_eligible( $rule, $user_roles ) {
		$required_roles = $rule['user_roles'] ?? [];
		if ( empty( $required_roles ) || in_array( 'all', $required_roles, true ) ) {
			return true;
		}

		$match = array_intersect( $user_roles, $required_roles );
		return ! empty( $match );
	}

	/**
	 * Check if rule qualifies for selected payment method.
	 *
	 * @param array  $rule
	 * @param string $chosen_payment
	 * @return bool
	 */
	public static function is_payment_method_eligible( $rule, $chosen_payment ) {
		$required_methods = $rule['payment_methods'] ?? [];
		if ( empty( $required_methods ) || in_array( 'all', $required_methods, true ) ) {
			return true;
		}

		// If a specific payment method is required, but none is selected yet (e.g. on Cart page)
		if ( empty( $chosen_payment ) ) {
			return false;
		}

		return in_array( $chosen_payment, $required_methods, true );
	}

	/**
	 * Check if rule satisfies Cart Min/Max subtotal thresholds.
	 *
	 * @param array $rule
	 * @param float $subtotal
	 * @return bool
	 */
	public static function is_cart_subtotal_eligible( $rule, $subtotal ) {
		$min = ! empty( $rule['min_cart_total'] ) ? floatval( $rule['min_cart_total'] ) : 0;
		$max = ! empty( $rule['max_cart_total'] ) ? floatval( $rule['max_cart_total'] ) : 0;

		if ( $min > 0 && $subtotal < $min ) {
			return false;
		}

		if ( $max > 0 && $subtotal > $max ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if a single cart item is eligible for this rule.
	 *
	 * @param array $cart_item
	 * @param array $rule
	 * @return bool
	 */
	public static function is_item_eligible( $cart_item, $rule ) {
		if ( empty( $cart_item['data'] ) || ! is_a( $cart_item['data'], 'WC_Product' ) ) {
			return false;
		}

		return self::is_product_eligible( $cart_item['data'], $rule, $cart_item );
	}

	/**
	 * Check if a product object is eligible for this discount rule.
	 *
	 * @param WC_Product $product
	 * @param array      $rule
	 * @param array      $cart_item Optional context if coming from cart.
	 * @return bool
	 */
	public static function is_product_eligible( $product, $rule, $cart_item = [] ) {
		if ( ! is_a( $product, 'WC_Product' ) ) {
			return false;
		}

		$product_id   = absint( ! empty( $cart_item['product_id'] ) ? $cart_item['product_id'] : $product->get_id() );
		$parent_id    = is_callable( [ $product, 'get_parent_id' ] ) ? absint( $product->get_parent_id() ) : 0;
		$variation_id = ! empty( $cart_item['variation_id'] ) ? absint( $cart_item['variation_id'] ) : ( ( $parent_id > 0 ) ? absint( $product->get_id() ) : 0 );
		$base_id      = ( $parent_id > 0 ) ? $parent_id : $product_id;

		// 1. PRODUCT TYPE CHECK
		$allowed_types = $rule['product_types'] ?? [ 'simple', 'variable' ];
		if ( ! empty( $allowed_types ) && ! in_array( 'all', $allowed_types, true ) ) {
			$is_variable = ( $variation_id > 0 ) || $product->is_type( 'variation' ) || $product->is_type( 'variable' );
			$is_simple   = $product->is_type( 'simple' );
			$is_grouped  = $product->is_type( 'grouped' );
			$is_external = $product->is_type( 'external' );

			$type_matched = false;
			if ( $is_variable && ( in_array( 'variable', $allowed_types, true ) || in_array( 'variation', $allowed_types, true ) ) ) {
				$type_matched = true;
			} elseif ( $is_simple && in_array( 'simple', $allowed_types, true ) ) {
				$type_matched = true;
			} elseif ( $is_grouped && in_array( 'grouped', $allowed_types, true ) ) {
				$type_matched = true;
			} elseif ( $is_external && in_array( 'external', $allowed_types, true ) ) {
				$type_matched = true;
			}

			if ( ! $type_matched ) {
				return false;
			}
		}

		// 2. EXCLUDE PRODUCTS CHECK
		// With WPML a rule made for the original product also covers its translations: both sides are compared by their default-language IDs.
		$match_ids        = array_values( array_unique( array_merge( WCDR_I18n::with_default_ids( [ $product_id, $base_id ], 'product' ), WCDR_I18n::with_default_ids( [ $variation_id ], 'product_variation' ) ) ) );
		$to_default_ids   = static function ( $list ) {
		return array_values( array_unique( array_merge( WCDR_I18n::with_default_ids( $list, 'product' ), WCDR_I18n::with_default_ids( $list, 'product_variation' ) ) ) );
		};
		$exclude_products = ! empty( $rule['exclude_products'] ) ? $to_default_ids( array_map( 'absint', (array) $rule['exclude_products'] ) ) : [];
		if ( ! empty( $exclude_products ) && array_intersect( $match_ids, $exclude_products ) ) {
		return false;
		}

		// 3. EXCLUDE CATEGORIES CHECK
		$exclude_categories = ! empty( $rule['exclude_categories'] ) ? array_map( 'absint', (array) $rule['exclude_categories'] ) : [];
		$product_categories = array_map( 'absint', self::get_product_categories( $base_id ) );
		if ( $product_id > 0 && $product_id !== $base_id ) {
			$child_categories = array_map( 'absint', self::get_product_categories( $product_id ) );
			if ( ! empty( $child_categories ) ) {
				$product_categories = array_values( array_unique( array_merge( $product_categories, $child_categories ) ) );
			}
		}
		if ( is_callable( [ $product, 'get_id' ] ) && $product->get_id() > 0 && $product->get_id() !== $base_id && $product->get_id() !== $product_id ) {
			$curr_categories = array_map( 'absint', self::get_product_categories( $product->get_id() ) );
			if ( ! empty( $curr_categories ) ) {
				$product_categories = array_values( array_unique( array_merge( $product_categories, $curr_categories ) ) );
			}
		}

		$product_categories = WCDR_I18n::with_default_ids( $product_categories, 'product_cat' );
		$exclude_categories = WCDR_I18n::with_default_ids( $exclude_categories, 'product_cat' );

		if ( ! empty( $exclude_categories ) && ! empty( $product_categories ) ) {
		$intersect = array_intersect( $product_categories, $exclude_categories );
			if ( ! empty( $intersect ) ) {
				return false; // Product belongs to an excluded category
			}
		}

		// 4. INCLUSION / TARGET SCOPE CHECK
		$apply_to = $rule['apply_to'] ?? 'all';

		if ( 'all' === $apply_to ) {
			return true;
		}

		$include_categories = ! empty( $rule['include_categories'] ) ? WCDR_I18n::with_default_ids( array_map( 'absint', (array) $rule['include_categories'] ), 'product_cat' ) : [];
		$include_products   = ! empty( $rule['include_products'] ) ? $to_default_ids( array_map( 'absint', (array) $rule['include_products'] ) ) : [];

		$cat_match = false;
		if ( ! empty( $include_categories ) && ! empty( $product_categories ) ) {
			$cat_intersect = array_intersect( $product_categories, $include_categories );
			if ( ! empty( $cat_intersect ) ) {
				$cat_match = true;
			}
		}

		$prod_match = false;
		if ( ! empty( $include_products ) && array_intersect( $match_ids, $include_products ) ) {
		$prod_match = true;
		}

		if ( 'categories' === $apply_to ) {
			return $cat_match;
		}

		if ( 'products' === $apply_to ) {
			return $prod_match;
		}

		if ( 'both' === $apply_to ) {
			return ( $cat_match || $prod_match );
		}

		return true;
	}

	/**
	 * Get immediate catalog discount for a product (shop, category, single product page).
	 * Returns array with discount information or null if no discount applies.
	 *
	 * @param WC_Product $product
	 * @return array|null
	 */
	public static function get_catalog_discount_for_product( $product ) {
		if ( ! is_a( $product, 'WC_Product' ) ) {
			return null;
		}

		$settings = WCDR_Rules_Manager::get_default_settings();
		if ( 'yes' !== ( $settings['enabled'] ?? 'yes' ) ) {
			return null;
		}

		if ( 'yes' !== ( $settings['show_catalog_strikethrough'] ?? 'yes' ) ) {
			return null;
		}

		$active_rules = WCDR_Rules_Manager::get_active_rules();
		if ( empty( $active_rules ) ) {
			return null;
		}

		$customer_roles = self::get_current_user_roles();

		$is_variable = $product->is_type( 'variable' );
		$min_reg     = 0;
		$max_reg     = 0;

		// Use regular price if available, else raw price
		$regular_price = floatval( $product->get_regular_price() );
		if ( $is_variable ) {
			$min_reg = is_callable( [ $product, 'get_variation_regular_price' ] ) ? floatval( $product->get_variation_regular_price( 'min', true ) ) : 0;
			$max_reg = is_callable( [ $product, 'get_variation_regular_price' ] ) ? floatval( $product->get_variation_regular_price( 'max', true ) ) : 0;
			if ( $min_reg <= 0 ) {
				$min_reg = is_callable( [ $product, 'get_variation_price' ] ) ? floatval( $product->get_variation_price( 'min', true ) ) : 0;
				$max_reg = is_callable( [ $product, 'get_variation_price' ] ) ? floatval( $product->get_variation_price( 'max', true ) ) : 0;
			}
			$regular_price = $min_reg;
		} elseif ( $product->is_type( 'variation' ) ) {
			if ( $regular_price <= 0 && is_callable( [ $product, 'get_parent_id' ] ) && $product->get_parent_id() ) {
				$parent = function_exists( 'wc_get_product' ) ? wc_get_product( $product->get_parent_id() ) : null;
				if ( $parent && is_a( $parent, 'WC_Product' ) ) {
					$regular_price = floatval( $parent->get_regular_price() );
					if ( $regular_price <= 0 ) {
						$regular_price = floatval( $parent->get_price() );
					}
				}
			}
		}

		if ( $regular_price <= 0 ) {
			$regular_price = floatval( $product->get_price() );
		}

		if ( $regular_price <= 0 ) {
			return null;
		}

		$applicable_catalog_rules = [];

		foreach ( $active_rules as $rule ) {
			// Skip rules that have catalog display disabled
			if ( isset( $rule['show_on_catalog'] ) && 'no' === $rule['show_on_catalog'] ) {
				continue;
			}

			// User role check
			if ( ! self::is_role_eligible( $rule, $customer_roles ) ) {
				continue;
			}

			// If rule strictly requires a specific payment method or cart subtotal condition,
			// it cannot be evaluated on catalog browse (it will be evaluated in cart/checkout)
			if ( ! empty( $rule['payment_methods'] ) && ! in_array( 'all', (array) $rule['payment_methods'], true ) ) {
				continue;
			}
			if ( ! empty( $rule['min_cart_total'] ) || ! empty( $rule['max_cart_total'] ) ) {
				continue;
			}

			// Check product eligibility (type, include/exclude categories, include/exclude products)
			if ( ! self::is_product_eligible( $product, $rule ) ) {
				continue;
			}

			// Extra conditions from add-ons (schedule, first order, ...).
			if ( ! apply_filters( 'wcdr_rule_applies', true, $rule, [ 'product' => $product ] ) ) {
				continue;
			}

			// Calculate discount on regular price
			$discount_type  = $rule['discount_type'] ?? 'percentage';
			$discount_value = floatval( $rule['discount_value'] ?? 0 );
			if ( $discount_value <= 0 ) {
				continue;
			}

			$discount_amount = 0;
			$rate            = 0;
			if ( 'percentage' === $discount_type ) {
				$rate            = max( 0, min( 100, $discount_value ) );
				$discount_amount = ( $regular_price * $rate ) / 100;
			} else {
				$discount_amount = min( $discount_value, $regular_price );
				$rate            = ( $regular_price > 0 ) ? ( $discount_amount / $regular_price ) * 100 : 0;
			}

			if ( ! empty( $rule['max_discount'] ) && floatval( $rule['max_discount'] ) > 0 ) {
				$discount_amount = min( $discount_amount, floatval( $rule['max_discount'] ) );
			}

			if ( $discount_amount > 0 ) {
				$applicable_catalog_rules[] = [
					'rule'            => $rule,
					'discount_amount' => $discount_amount,
					'rate'            => $rate,
				];
			}
		}

		if ( empty( $applicable_catalog_rules ) ) {
			return null;
		}

		$combination    = $settings['rule_combination'] ?? 'stack';
		$total_discount = 0;
		$applied_rate   = 0;

		if ( 'highest' === $combination ) {
			usort( $applicable_catalog_rules, static function( $a, $b ) {
				if ( $a['discount_amount'] === $b['discount_amount'] ) {
					return 0;
				}
				return ( $a['discount_amount'] > $b['discount_amount'] ) ? -1 : 1;
			} );
			$total_discount = $applicable_catalog_rules[0]['discount_amount'];
			$applied_rate   = $applicable_catalog_rules[0]['rate'];
			$best_rule      = $applicable_catalog_rules[0]['rule'];
		} else {
			// Stack
			foreach ( $applicable_catalog_rules as $ar ) {
				$total_discount += $ar['discount_amount'];
				$applied_rate   += $ar['rate'];
			}
			$total_discount = min( $total_discount, $regular_price );
			$best_rule      = $applicable_catalog_rules[0]['rule'];
		}

		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		$is_range = ( $is_variable && $min_reg > 0 && $max_reg > $min_reg );

		// The regular price is shown as it really is; the discounted price is the real selling price.
		$diff             = round( $total_discount, $decimals );
		$discounted_price = max( 0, round( $regular_price - $diff, $decimals ) );
		$original_price   = $regular_price;

		if ( $discounted_price >= $regular_price ) {
			return null;
		}

		if ( $is_range ) {
			$max_discount_amt = ( 'percentage' === ( $best_rule['discount_type'] ?? 'percentage' ) )
				? ( $max_reg * ( $applied_rate / 100 ) )
				: min( $total_discount, $max_reg );
			$min_discounted_price = $discounted_price;
			$max_discounted_price = max( 0, round( $max_reg - $max_discount_amt, $decimals ) );
			$min_regular_price    = $min_reg;
			$max_regular_price    = $max_reg;
		} else {
			$min_discounted_price = $discounted_price;
			$max_discounted_price = $discounted_price;
			$min_regular_price    = $original_price;
			$max_regular_price    = $original_price;
		}

		$display_badge_rate = ( $regular_price > 0 ) ? ( $diff / $regular_price ) * 100 : 0;

		return [
			'regular_price'        => $original_price,
			'discount_amount'      => $diff,
			'discounted_price'     => $discounted_price,
			'rate'                 => round( $display_badge_rate ),
			'rule'                 => $best_rule,
			'is_variable'          => $is_variable,
			'is_range'             => $is_range,
			'min_regular_price'    => $min_regular_price,
			'max_regular_price'    => $max_regular_price,
			'min_discounted_price' => $min_discounted_price,
			'max_discounted_price' => $max_discounted_price,
		];
	}

	/**
	 * Whether a rule can give a discount. A rule with a value of 0 can still discount through an add-on (quantity tiers).
	 *
	 * @param array $rule Rule.
	 * @return bool
	 */
	public static function rule_has_discount( $rule ) {
		return floatval( $rule['discount_value'] ?? 0 ) > 0 || (bool) apply_filters( 'wcdr_rule_has_discount', false, $rule );
	}

	/**
	 * Lets add-ons change the discount of one unit (for example by quantity). Never more than the unit price.
	 *
	 * @param float        $unit_discount Discount of one unit.
	 * @param array        $rule          Rule.
	 * @param float        $unit_price    Price of one unit before any discount.
	 * @param array        $cart_item     Cart item.
	 * @param WC_Cart|null $cart          Cart.
	 * @return float
	 */
	public static function filter_unit_discount( $unit_discount, $rule, $unit_price, $cart_item, $cart = null ) {
		$changed = (float) apply_filters( 'wcdr_unit_discount', $unit_discount, $rule, $unit_price, $cart_item, $cart );
		return max( 0.0, min( $changed, (float) $unit_price ) );
	}

	/**
	 * Calculate discount amount for a rule based on eligible cart items.
	 *
	 * @param array   $rule
	 * @param WC_Cart $cart
	 * @return array ['discount_amount' => float, 'qualifying_subtotal' => float, 'eligible_items' => array]
	 */
	public static function calculate_rule_discount( $rule, $cart ) {
		$eligible_items      = [];
		$qualifying_subtotal = 0;
		$discount_type       = $rule['discount_type'] ?? 'percentage';
		$discount_value      = floatval( $rule['discount_value'] ?? 0 );
		$max_discount        = floatval( $rule['max_discount'] ?? 0 );

		if ( ! self::rule_has_discount( $rule ) ) {
			return [
				'discount_amount'     => 0,
				'qualifying_subtotal' => 0,
				'qualifying_net'      => 0,
				'eligible_items'      => [],
			];
		}

		$unit_total     = 0;
		$qualifying_net = 0;

		foreach ( $cart->get_cart() as $item_key => $cart_item ) {
			if ( self::is_item_eligible( $cart_item, $rule ) ) {
				$qty           = absint( $cart_item['quantity'] ?? 1 );
				$unit          = self::get_item_baseline_price( $cart_item );
				$line_subtotal = $unit * $qty;

				$qualifying_subtotal += $line_subtotal;
				$qualifying_net      += isset( $cart_item['line_subtotal'] ) ? floatval( $cart_item['line_subtotal'] ) : $line_subtotal;
				// The discount of one unit: a percentage or a fixed amount, never more than the maximum cap or the price.
				$unit_discount = ( 'fixed' === $discount_type ) ? min( $discount_value, $unit ) : $unit * max( 0, min( 100, $discount_value ) ) / 100;
				if ( $max_discount > 0 ) {
					$unit_discount = min( $unit_discount, $max_discount );
				}
				$unit_discount = self::filter_unit_discount( $unit_discount, $rule, $unit, $cart_item, $cart );
				$unit_total   += $unit_discount * $qty;

				$eligible_items[ $item_key ] = [
					'product_id'    => $cart_item['product_id'],
					'variation_id'  => $cart_item['variation_id'] ?? 0,
					'quantity'      => $qty,
					'line_subtotal' => $line_subtotal,
				];
			}
		}

		if ( empty( $eligible_items ) || $qualifying_subtotal <= 0 ) {
			return [
				'discount_amount'     => 0,
				'qualifying_subtotal' => 0,
				'qualifying_net'      => 0,
				'eligible_items'      => [],
			];
		}

		$discount_amount = 0;

		// Same amounts as the item price mode and the shop prices: the cap applies to every unit.
		$discount_amount = $unit_total;

		// Precision rounding based on WooCommerce currency decimals
		$decimals        = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		$discount_amount = round( $discount_amount, $decimals );

		// Discount cannot exceed qualifying subtotal
		$discount_amount = min( $discount_amount, $qualifying_subtotal );

		return [
			'discount_amount'     => (float) $discount_amount,
			'qualifying_subtotal' => (float) $qualifying_subtotal,
			'qualifying_net'      => (float) $qualifying_net,
			'eligible_items'      => $eligible_items,
		];
	}

	/**
	 * Get category IDs for a product including all parent ancestors.
	 *
	 * @param int $product_id
	 * @return array Array of term IDs
	 */
	public static function get_product_categories( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return [];
		}

		if ( isset( self::$category_cache[ $product_id ] ) ) {
			return self::$category_cache[ $product_id ];
		}

		$term_ids = [];
		if ( function_exists( 'wc_get_product_term_ids' ) ) {
			$direct_ids = wc_get_product_term_ids( $product_id, 'product_cat' );
			if ( ! empty( $direct_ids ) ) {
				foreach ( $direct_ids as $tid ) {
					$term_ids[] = (int) $tid;
				}
			}
		}

		// Fallback: WordPress standard post terms
		if ( empty( $term_ids ) && function_exists( 'wp_get_post_terms' ) ) {
			$terms = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'ids' ] );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				foreach ( $terms as $tid ) {
					$term_ids[] = (int) $tid;
				}
			}
		}

		// Fallback 2: get_the_terms
		if ( empty( $term_ids ) && function_exists( 'get_the_terms' ) ) {
			$terms = get_the_terms( $product_id, 'product_cat' );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				foreach ( $terms as $t ) {
					$tid = (int) ( is_object( $t ) ? $t->term_id : $t );
					if ( $tid > 0 ) {
						$term_ids[] = $tid;
					}
				}
			}
		}

		// Include ancestor category IDs
		if ( ! empty( $term_ids ) && function_exists( 'get_ancestors' ) ) {
			$ancestor_ids = [];
			foreach ( $term_ids as $tid ) {
				$ancestors = get_ancestors( (int) $tid, 'product_cat', 'taxonomy' );
				if ( ! empty( $ancestors ) ) {
					foreach ( $ancestors as $aid ) {
						$ancestor_ids[] = (int) $aid;
					}
				}
			}
			if ( ! empty( $ancestor_ids ) ) {
				$term_ids = array_merge( $term_ids, $ancestor_ids );
			}
		}

		$term_ids = array_values( array_unique( array_map( 'absint', $term_ids ) ) );
		self::$category_cache[ $product_id ] = $term_ids;

		return $term_ids;
	}
}
