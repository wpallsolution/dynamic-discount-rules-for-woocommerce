<?php
/**
 * Frontend Hooks and Discount Application Class.
 *
 * Handles catalog price displays, cart fees, item price modifications,
 * mini-cart/drawer subtotals, order item metadata, and checkout AJAX updates.
 *
 * @package WCDR
 */

defined( 'ABSPATH' ) || exit;

class WCDR_Frontend {

	/**
	 * Singleton instance.
	 *
	 * @var WCDR_Frontend|null
	 */
	private static $instance = null;

	/**
	 * Cache of applied rules in current request.
	 *
	 * @var array
	 */
	private $applied_rules = [];

	/**
	 * Guard flag to avoid recursive loops in price filters.
	 *
	 * @var bool
	 */
	private $calculating_price = false;

	/**
	 * Track which rule IDs have been applied directly to item prices,
	 * so apply_cart_fee_discounts knows not to duplicate their base discount as a fee.
	 *
	 * @var array<string, bool>
	 */
	private $items_discounted_by_rules = [];

	/**
	 * Get singleton instance.
	 *
	 * @return WCDR_Frontend
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
		// Immediate Catalog / Shop / Product Page Strikethrough Pricing
		add_filter( 'woocommerce_product_get_price', [ $this, 'filter_product_price' ], 20, 2 );
		add_filter( 'woocommerce_product_get_sale_price', [ $this, 'filter_product_sale_price' ], 20, 2 );
		add_filter( 'woocommerce_product_variation_get_price', [ $this, 'filter_product_price' ], 20, 2 );
		add_filter( 'woocommerce_product_variation_get_sale_price', [ $this, 'filter_product_sale_price' ], 20, 2 );
		add_filter( 'woocommerce_product_is_on_sale', [ $this, 'filter_product_is_on_sale' ], 20, 2 );
		add_filter( 'woocommerce_get_price_html', [ $this, 'filter_price_html' ], 20, 2 );
		add_filter( 'woocommerce_variation_prices_price', [ $this, 'filter_product_price' ], 20, 2 );
		add_filter( 'woocommerce_variation_prices_sale_price', [ $this, 'filter_product_sale_price' ], 20, 2 );

		// Ensure WooCommerce native sale price format adheres to exact order:
		// First Discounted -> Then Strikethrough Regular -> Then (10% off)
		add_filter( 'woocommerce_format_sale_price', [ $this, 'filter_woocommerce_format_sale_price' ], 20, 3 );

		// Ensure theme & WooCommerce sale flash / badge displays correct discount percentage instead of 0% or -0%
		add_filter( 'woocommerce_sale_flash', [ $this, 'filter_woocommerce_sale_flash' ], 999, 3 );

		// Apply Cart Level Discount Fees (including payment bonus discounts)
		add_action( 'woocommerce_cart_calculate_fees', [ $this, 'apply_cart_fee_discounts' ], 20, 1 );

		// Apply Item Level Price Discounts (Directly sets line price so subtotals, totals, orders, and emails reflect discounted price)
		add_action( 'woocommerce_before_calculate_totals', [ $this, 'apply_item_price_discounts' ], 20, 1 );

		// Display price formatted in cart table & side drawer (Discounted first, strikethrough second, (10% off) third)
		add_filter( 'woocommerce_cart_item_price', [ $this, 'render_discounted_item_price' ], 20, 3 );

		// Filter line item subtotal in cart and mini-cart to reflect discounted price
		add_filter( 'woocommerce_cart_item_subtotal', [ $this, 'render_discounted_item_subtotal' ], 20, 3 );

		// Save discount details to order line item meta upon checkout
		add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'save_discount_order_item_meta' ], 20, 4 );

		// Enqueue frontend scripts on cart & checkout
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_scripts' ] );

		// Optional cart / checkout discount notice
		add_action( 'woocommerce_before_cart', [ $this, 'display_cart_notice' ], 10 );
		add_action( 'woocommerce_before_checkout_form', [ $this, 'display_cart_notice' ], 10 );
	}

	/**
	 * Helper: Format price HTML in the strict requested order:
	 * 1. Discounted Sale Price (ins)
	 * 2. Strikethrough Regular Price (del)
	 * 3. (10% off) Badge
	 *
	 * @param float|string $discounted
	 * @param float|string $regular
	 * @param float|int    $rate
	 * @param string       $badge_text
	 * @return string
	 */
	public static function format_discount_price_html( $discounted, $regular, $rate = 0, $badge_text = '' ) {
		$discounted_formatted = is_numeric( $discounted ) ? wc_price( $discounted ) : $discounted;
		$regular_formatted    = is_numeric( $regular ) ? wc_price( $regular ) : $regular;

		if ( empty( $badge_text ) ) {
			if ( $rate > 0 ) {
				$badge_text = '(' . round( $rate ) . '% off)';
			} else {
				$badge_text = '';
			}
		}

		$badge_html = '';
		if ( ! empty( $badge_text ) ) {
			$badge_html = sprintf(
				'<span class="wcdr-discount-badge" style="display:inline-block; font-size:0.85em; font-weight:600; color:#16a34a; background:#e8f5e9; padding:2px 7px; border-radius:4px; vertical-align:middle; line-height:1.2; margin-left:4px;">%s</span>',
				esc_html( $badge_text )
			);
		}

		return sprintf(
			'<span class="wcdr-price-wrapper" style="display:inline-flex; align-items:baseline; flex-wrap:wrap; gap:6px;"><ins class="wcdr-discounted-price" style="text-decoration:none; font-weight:700; color:#16a34a;">%s</ins><del class="wcdr-regular-price" aria-hidden="true" style="opacity:0.6; text-decoration:line-through; font-weight:normal;">%s</del>%s</span>',
			$discounted_formatted,
			$regular_formatted,
			$badge_html
		);
	}

	/**
	 * Filter raw product price on catalog / single product.
	 *
	 * @param float|string $price
	 * @param WC_Product   $product
	 * @return float|string
	 */
	public function filter_product_price( $price, $product ) {
		if ( $this->calculating_price || ( is_admin() && ! defined( 'DOING_AJAX' ) ) ) {
			return $price;
		}

		if ( ! is_a( $product, 'WC_Product' ) ) {
			return $price;
		}

		$this->calculating_price = true;
		$discount_info           = WCDR_Engine::get_catalog_discount_for_product( $product );
		$this->calculating_price = false;

		if ( ! empty( $discount_info['discounted_price'] ) ) {
			return (string) $discount_info['discounted_price'];
		}

		return $price;
	}

	/**
	 * Filter product sale price on catalog / single product.
	 *
	 * @param float|string $sale_price
	 * @param WC_Product   $product
	 * @return float|string
	 */
	public function filter_product_sale_price( $sale_price, $product ) {
		if ( $this->calculating_price || ( is_admin() && ! defined( 'DOING_AJAX' ) ) ) {
			return $sale_price;
		}

		if ( ! is_a( $product, 'WC_Product' ) ) {
			return $sale_price;
		}

		$this->calculating_price = true;
		$discount_info           = WCDR_Engine::get_catalog_discount_for_product( $product );
		$this->calculating_price = false;

		if ( ! empty( $discount_info['discounted_price'] ) ) {
			return (string) $discount_info['discounted_price'];
		}

		return $sale_price;
	}

	/**
	 * Mark product as on sale if discount rule applies.
	 *
	 * @param bool       $is_on_sale
	 * @param WC_Product $product
	 * @return bool
	 */
	public function filter_product_is_on_sale( $is_on_sale, $product ) {
		if ( $is_on_sale ) {
			return true;
		}

		if ( ! is_a( $product, 'WC_Product' ) || $this->calculating_price ) {
			return $is_on_sale;
		}

		$this->calculating_price = true;
		$discount_info           = WCDR_Engine::get_catalog_discount_for_product( $product );
		$this->calculating_price = false;

		if ( ! empty( $discount_info['discounted_price'] ) && $discount_info['discounted_price'] < $discount_info['regular_price'] ) {
			return true;
		}

		return $is_on_sale;
	}

	/**
	 * Filter WooCommerce sale flash / badge to display the correct discount percentage
	 * instead of 0% or -0% generated by themes.
	 *
	 * @param string     $html
	 * @param WP_Post    $post
	 * @param WC_Product $product
	 * @return string
	 */
	public function filter_woocommerce_sale_flash( $html, $post = null, $product = null ) {
		if ( ! is_a( $product, 'WC_Product' ) ) {
			if ( $post && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $post );
			} elseif ( isset( $GLOBALS['product'] ) && is_a( $GLOBALS['product'], 'WC_Product' ) ) {
				$product = $GLOBALS['product'];
			}
		}

		if ( ! is_a( $product, 'WC_Product' ) ) {
			return $html;
		}

		$discount_info = WCDR_Engine::get_catalog_discount_for_product( $product );
		if ( empty( $discount_info ) || empty( $discount_info['rate'] ) ) {
			return $html;
		}

		$rate = round( $discount_info['rate'] );

		// If HTML already contains an encoded or plain 0% (e.g. &#045;0&#037;, -0%, 0%), replace it
		if ( preg_match( '/(&#045;|-)?\s*0\s*(&#037;|%)/i', $html ) ) {
			return preg_replace( '/(&#045;|-)?\s*0\s*(&#037;|%)/i', '&#045;' . $rate . '&#037;', $html );
		}

		// If it's a generic sale flash without percentage (e.g. standard WooCommerce <span class="onsale">Sale!</span>),
		// enhance it with the actual rate
		if ( false !== strpos( $html, 'onsale' ) && false === strpos( $html, '%' ) ) {
			return '<span class="onsale">-' . $rate . '%</span>';
		}

		return $html;
	}

	/**
	 * Filter price HTML to show exact order:
	 * First Discounted -> Then Strikethrough Regular -> Then (10% off)
	 *
	 * @param string     $price_html
	 * @param WC_Product $product
	 * @return string
	 */
	public function filter_price_html( $price_html, $product ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return $price_html;
		}

		if ( ! is_a( $product, 'WC_Product' ) || $this->calculating_price ) {
			return $price_html;
		}

		$this->calculating_price = true;
		$discount_info           = WCDR_Engine::get_catalog_discount_for_product( $product );
		$this->calculating_price = false;

		if ( ! empty( $discount_info['discounted_price'] ) && $discount_info['discounted_price'] < $discount_info['regular_price'] ) {
			$rate = ! empty( $discount_info['rate'] ) ? $discount_info['rate'] : round( ( ( $discount_info['regular_price'] - $discount_info['discounted_price'] ) / $discount_info['regular_price'] ) * 100 );

			if ( ! empty( $discount_info['is_range'] ) ) {
				$discounted_range = wc_price( $discount_info['min_discounted_price'] ) . ' &ndash; ' . wc_price( $discount_info['max_discounted_price'] );
				$regular_range    = wc_price( $discount_info['min_regular_price'] ) . ' &ndash; ' . wc_price( $discount_info['max_regular_price'] );
				return self::format_discount_price_html( $discounted_range, $regular_range, $rate );
			}

			$regular_display    = function_exists( 'wc_get_price_to_display' ) ? wc_get_price_to_display( $product, [ 'price' => $discount_info['regular_price'] ] ) : $discount_info['regular_price'];
			$discounted_display = function_exists( 'wc_get_price_to_display' ) ? wc_get_price_to_display( $product, [ 'price' => $discount_info['discounted_price'] ] ) : $discount_info['discounted_price'];

			return self::format_discount_price_html( $discounted_display, $regular_display, $rate );
		}

		return $price_html;
	}

	/**
	 * Hook into wc_format_sale_price to reverse standard WooCommerce order:
	 * Replaces <del>Regular</del> <ins>Sale</ins> with:
	 * <ins>Sale</ins> <del>Regular</del> (10% off)
	 *
	 * @param string       $price
	 * @param float|string $regular_price
	 * @param float|string $sale_price
	 * @return string
	 */
	public function filter_woocommerce_format_sale_price( $price, $regular_price, $sale_price ) {
		// Prevent double-wrapping if already processed
		if ( false !== strpos( $price, 'wcdr-price-wrapper' ) ) {
			return $price;
		}

		$reg_num  = is_numeric( $regular_price ) ? floatval( $regular_price ) : floatval( preg_replace( '/[^0-9.]/', '', wp_strip_all_tags( (string) $regular_price ) ) );
		$sale_num = is_numeric( $sale_price ) ? floatval( $sale_price ) : floatval( preg_replace( '/[^0-9.]/', '', wp_strip_all_tags( (string) $sale_price ) ) );
		$rate     = 0;
		if ( $reg_num > 0 && $sale_num < $reg_num ) {
			$rate = round( ( ( $reg_num - $sale_num ) / $reg_num ) * 100 );
		}

		return self::format_discount_price_html( $sale_price, $regular_price, $rate );
	}

	/**
	 * Apply Cart Fee discounts (Negative fee in cart totals, including payment bonuses).
	 *
	 * @param WC_Cart $cart
	 */
	public function apply_cart_fee_discounts( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		if ( ! $cart || $cart->is_empty() ) {
			return;
		}

		$applicable_rules = WCDR_Engine::get_applicable_rules( $cart );
		if ( empty( $applicable_rules ) ) {
			return;
		}

		$settings   = WCDR_Rules_Manager::get_default_settings();
		$is_taxable = ( 'taxable' === ( $settings['tax_handling'] ?? 'taxable' ) );

		$this->applied_rules = [];

		foreach ( $applicable_rules as $rule ) {
			$apply_mode       = $rule['apply_mode'] ?? 'cart_fee';
			$is_payment_bonus = ! empty( $rule['is_payment_bonus'] );

			// 1. Payment bonus discounts ALWAYS get added as cart fee (e.g. +5% Online Payment discount)
			if ( $is_payment_bonus ) {
				$discount_amount = floatval( $rule['calculated_discount'] );
				if ( $discount_amount > 0 ) {
					// Safety: Ensure fee discount never exceeds net cart amount after coupons
					$cart_subtotal   = floatval( $cart->get_subtotal() );
					$coupon_discount = is_callable( [ $cart, 'get_discount_total' ] ) ? floatval( $cart->get_discount_total() ) : 0.0;
					$max_allowed     = max( 0.0, $cart_subtotal - $coupon_discount );
					$discount_amount = min( $discount_amount, $max_allowed );

					if ( $discount_amount > 0 ) {
						$cart->add_fee( ! empty( $rule['is_payment_bonus'] ) ? $rule['title'] : WCDR_I18n::rule_title( $rule ), -$discount_amount, $is_taxable );
						$this->applied_rules[] = [
							'rule'   => $rule,
							'amount' => $discount_amount,
						];
					}
				}
				continue;
			}

			// 2. Base discounts: If this rule was ALREADY applied to item prices (and thus subtotal),
			// DO NOT duplicate it as a cart fee!
			if ( ! empty( $this->items_discounted_by_rules[ $rule['id'] ] ) || ( $rule['show_on_catalog'] ?? 'yes' ) === 'yes' || 'item_price' === $apply_mode ) {
				continue;
			}

			// 3. Otherwise, for pure cart-level fee rules
			if ( 'cart_fee' === $apply_mode ) {
				$discount_amount = floatval( $rule['calculated_discount'] );
				if ( $discount_amount <= 0 ) {
					continue;
				}

				// Format title with discount percentage or label
				$title = WCDR_I18n::rule_title( $rule );
				if ( 'percentage' === ( $rule['discount_type'] ?? 'percentage' ) ) {
					$title .= ' (' . floatval( $rule['discount_value'] ) . '%)';
				}

				// Safety: Ensure fee discount never exceeds net cart amount after coupons
				$cart_subtotal   = floatval( $cart->get_subtotal() );
				$coupon_discount = is_callable( [ $cart, 'get_discount_total' ] ) ? floatval( $cart->get_discount_total() ) : 0.0;
				$max_allowed     = max( 0.0, $cart_subtotal - $coupon_discount );
				$discount_amount = min( $discount_amount, $max_allowed );

				if ( $discount_amount > 0 ) {
					$cart->add_fee( $title, -$discount_amount, $is_taxable );
					$this->applied_rules[] = [
						'rule'   => $rule,
						'amount' => $discount_amount,
					];
				}
			}
		}
	}

	/**
	 * Apply Item Price discounts directly to product objects in cart.
	 * Directly adjusts product prices and line totals in cart so that:
	 * - Mini-cart drawer subtotal reflects discounted price
	 * - Cart subtotal reflects discounted price
	 * - Checkout subtotal reflects discounted price
	 * - Order line items and order totals reflect discounted price
	 * - Customer & admin order confirmation emails reflect discounted price
	 *
	 * @param WC_Cart $cart
	 */
	public function apply_item_price_discounts( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		if ( ! $cart || $cart->is_empty() ) {
			return;
		}

		$settings = WCDR_Rules_Manager::get_default_settings();
		if ( 'yes' !== ( $settings['enabled'] ?? 'yes' ) ) {
			return;
		}

		$active_rules = WCDR_Rules_Manager::get_active_rules();
		if ( empty( $active_rules ) ) {
			return;
		}

		$this->items_discounted_by_rules = [];

		// Customer roles & chosen payment method
		$customer_roles = WCDR_Engine::get_current_user_roles();
		$chosen_payment = WCDR_Engine::get_chosen_payment_method();
		$raw_subtotal   = WCDR_Engine::get_raw_cart_subtotal( $cart );

		// Combination setting (stack vs highest)
		$combination_mode = $settings['rule_combination'] ?? 'stack';
		$decimals         = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;

		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			/** @var WC_Product $product */
			$product = $cart_item['data'];
			if ( ! is_a( $product, 'WC_Product' ) ) {
				continue;
			}

			// Determine original baseline regular price
			$regular_price = WCDR_Engine::get_item_baseline_price( $cart_item );

			if ( $regular_price <= 0 ) {
				continue;
			}

			// Cache baseline regular price on cart item
			$cart->cart_contents[ $cart_item_key ]['wcdr_original_price'] = $regular_price;

			// Find all qualifying discount rules for this item
			$matching_discounts = [];

			foreach ( $active_rules as $rule ) {
				// Dynamic payment bonuses are applied at checkout totals breakdown, not on item prices
				if ( ! empty( $rule['is_payment_bonus'] ) ) {
				continue;
				}

				// "Cart Total Fee" rules without a catalog price are taken off as a fee line instead.
				if ( 'cart_fee' === ( $rule['apply_mode'] ?? 'cart_fee' ) && 'no' === ( $rule['show_on_catalog'] ?? 'yes' ) ) {
				continue;
				}

				if ( ! WCDR_Engine::is_role_eligible( $rule, $customer_roles ) ) {
					continue;
				}
				if ( ! WCDR_Engine::is_payment_method_eligible( $rule, $chosen_payment ) ) {
					continue;
				}
				if ( ! WCDR_Engine::is_cart_subtotal_eligible( $rule, $raw_subtotal ) ) {
					continue;
				}
				if ( ! WCDR_Engine::is_item_eligible( $cart_item, $rule ) ) {
					continue;
				}
				if ( ! apply_filters( 'wcdr_rule_applies', true, $rule, [ 'cart' => $cart, 'cart_item' => $cart_item ] ) ) {
					continue;
				}

				$discount_type  = $rule['discount_type'] ?? 'percentage';
				$discount_value = floatval( $rule['discount_value'] ?? 0 );
				if ( ! WCDR_Engine::rule_has_discount( $rule ) ) {
					continue;
				}

				$item_discount = 0;
				$rate          = 0;
				if ( 'percentage' === $discount_type ) {
					$rate          = max( 0, min( 100, $discount_value ) );
					$item_discount = ( $regular_price * $rate ) / 100;
				} else {
					$item_discount = min( $discount_value, $regular_price );
					$rate          = ( $regular_price > 0 ) ? ( $item_discount / $regular_price ) * 100 : 0;
				}

				if ( ! empty( $rule['max_discount'] ) && floatval( $rule['max_discount'] ) > 0 ) {
					$item_discount = min( $item_discount, floatval( $rule['max_discount'] ) );
				}

				$filtered_discount = WCDR_Engine::filter_unit_discount( $item_discount, $rule, $regular_price, $cart_item, $cart );
				if ( $filtered_discount !== $item_discount ) {
					$item_discount = $filtered_discount;
					$rate          = ( $regular_price > 0 ) ? ( $item_discount / $regular_price ) * 100 : 0;
				}

				if ( $item_discount > 0 ) {
					$matching_discounts[] = [
						'rule'     => $rule,
						'rule_id'  => $rule['id'],
						'discount' => $item_discount,
						'rate'     => $rate,
					];
				}
			}

			if ( empty( $matching_discounts ) ) {
				// No discount for this item - restore regular price
				$product->set_price( $regular_price );
				unset( $cart->cart_contents[ $cart_item_key ]['wcdr_discounted_price'] );
				unset( $cart->cart_contents[ $cart_item_key ]['wcdr_discount_percent'] );
				continue;
			}

			// Apply combination mode (highest vs stack)
			$applied_discount = 0;
			$applied_rate     = 0;

			if ( 'highest' === $combination_mode ) {
				usort(
					$matching_discounts,
					static function ( $a, $b ) {
						if ( $a['discount'] === $b['discount'] ) {
							return 0;
						}
						return ( $a['discount'] > $b['discount'] ) ? -1 : 1;
					}
				);
				$applied_discount = $matching_discounts[0]['discount'];
				$applied_rate     = $matching_discounts[0]['rate'];
				$this->items_discounted_by_rules[ $matching_discounts[0]['rule_id'] ] = true;
			} else {
				// Stack
				foreach ( $matching_discounts as $md ) {
					$applied_discount += $md['discount'];
					$applied_rate     += $md['rate'];
					$this->items_discounted_by_rules[ $md['rule_id'] ] = true;
				}
				$applied_discount = min( $applied_discount, $regular_price );
			}

			$diff             = round( $applied_discount, $decimals );
			$discounted_price = max( 0, round( $regular_price - $diff, $decimals ) );

			if ( $discounted_price < $regular_price ) {
				$product->set_price( $discounted_price );
				$product->set_sale_price( $discounted_price );

				$cart->cart_contents[ $cart_item_key ]['wcdr_original_price']   = $regular_price;
				$cart->cart_contents[ $cart_item_key ]['wcdr_discounted_price'] = $discounted_price;
				$cart->cart_contents[ $cart_item_key ]['wcdr_discount_percent'] = round( ( $diff / $regular_price ) * 100 );
			} else {
				$product->set_price( $regular_price );
				unset( $cart->cart_contents[ $cart_item_key ]['wcdr_discounted_price'] );
				unset( $cart->cart_contents[ $cart_item_key ]['wcdr_discount_percent'] );
			}
		}
	}

	/**
	 * Display price formatted in exact order:
	 * First Discounted -> Then Strikethrough Regular -> Then (10% off)
	 *
	 * @param string $price_html
	 * @param array  $cart_item
	 * @param string $cart_item_key
	 * @return string
	 */
	public function render_discounted_item_price( $price_html, $cart_item, $cart_item_key ) {
		if ( ! empty( $cart_item['wcdr_original_price'] ) && ! empty( $cart_item['wcdr_discounted_price'] ) ) {
			$original   = floatval( $cart_item['wcdr_original_price'] );
			$discounted = floatval( $cart_item['wcdr_discounted_price'] );

			if ( $discounted < $original ) {
				$rate = ! empty( $cart_item['wcdr_discount_percent'] )
					? floatval( $cart_item['wcdr_discount_percent'] )
					: ( ( $original > 0 ) ? round( ( ( $original - $discounted ) / $original ) * 100 ) : 0 );

				return self::format_discount_price_html( $discounted, $original, $rate );
			}
		}

		return $price_html;
	}

	/**
	 * Render discounted item line subtotal in cart and mini-cart drawer.
	 *
	 * @param string $subtotal_html
	 * @param array  $cart_item
	 * @param string $cart_item_key
	 * @return string
	 */
	public function render_discounted_item_subtotal( $subtotal_html, $cart_item, $cart_item_key ) {
		if ( ! empty( $cart_item['wcdr_discounted_price'] ) ) {
			$discounted = floatval( $cart_item['wcdr_discounted_price'] );
			$qty        = absint( $cart_item['quantity'] ?? 1 );
			return wc_price( $discounted * $qty );
		}

		return $subtotal_html;
	}

	/**
	 * Save original regular price and discount information into order line item meta upon checkout.
	 *
	 * @param WC_Order_Item_Product $item
	 * @param string                $cart_item_key
	 * @param array                 $values
	 * @param WC_Order              $order
	 */
	public function save_discount_order_item_meta( $item, $cart_item_key, $values, $order ) {
		if ( ! empty( $values['wcdr_original_price'] ) && ! empty( $values['wcdr_discounted_price'] ) ) {
			if ( $values['wcdr_discounted_price'] < $values['wcdr_original_price'] ) {
				$item->add_meta_data( '_wcdr_regular_price', $values['wcdr_original_price'], true );
				$item->add_meta_data( '_wcdr_discounted_price', $values['wcdr_discounted_price'], true );
				if ( ! empty( $values['wcdr_discount_percent'] ) ) {
					$item->add_meta_data( '_wcdr_discount', $values['wcdr_discount_percent'] . '% off', true );
				}
			}
		}
	}

	/**
	 * Display optional cart notice when discounts are active.
	 */
	public function display_cart_notice() {
		if ( is_admin() || ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return;
		}

		$settings = WCDR_Rules_Manager::get_default_settings();
		if ( 'yes' !== ( $settings['show_cart_notice'] ?? 'yes' ) ) {
			return;
		}

		// What the customer really saves: the item price discounts plus the discount lines in the totals.
		$total_savings = 0;
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( ! empty( $cart_item['wcdr_original_price'] ) && ! empty( $cart_item['wcdr_discounted_price'] ) ) {
				$total_savings += ( floatval( $cart_item['wcdr_original_price'] ) - floatval( $cart_item['wcdr_discounted_price'] ) ) * absint( $cart_item['quantity'] ?? 1 );
			}
		}
		foreach ( $this->applied_rules as $applied ) {
			$total_savings += floatval( $applied['amount'] );
		}

		if ( $total_savings <= 0 ) {
			return;
		}

		$notice_text = ! empty( $settings['cart_notice_text'] )
		? WCDR_I18n::translate( 'cart_notice_text', $settings['cart_notice_text'] )
			: __( 'Discount applied: You saved {discount_amount} on qualifying items!', 'dynamic-discount-rules-for-woocommerce' );

		$formatted_savings = wc_price( $total_savings );
		$message           = str_replace( '{discount_amount}', $formatted_savings, $notice_text );

		?>
		<div class="woocommerce-message wcdr-savings-notice" style="border-left-color: #16a34a; background-color: #f0fdf4; color: #166534; padding: 12px 18px; margin-bottom: 20px; border-radius: 4px; display: flex; align-items: center; gap: 8px;">
			<svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor" style="flex-shrink:0;">
				<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
			</svg>
			<span><?php echo wp_kses_post( $message ); ?></span>
		</div>
		<?php
	}

	/**
	 * Enqueue checkout scripts and frontend styling for price layout.
	 */
	public function enqueue_frontend_scripts() {
		wp_enqueue_style(
			'wcdr-frontend',
			WCDR_PLUGIN_URL . 'assets/css/frontend.css',
			[],
			WCDR_VERSION
		);

		wp_enqueue_script(
			'wcdr-frontend',
			WCDR_PLUGIN_URL . 'assets/js/frontend.js',
			[ 'jquery' ],
			WCDR_VERSION,
			true
		);
	}
}
