<?php
/**
 * Smoke test for the discount maths. Run on a development site:
 *
 *     wp eval-file wp-content/plugins/dynamic-discount-rules-for-woocommerce/tests/smoke-test.php
 *
 * It creates one product and a few rules, checks real cart totals, and removes everything again.
 * It needs WooCommerce and this plugin to be active. Do not run it on a live store.
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WCDR_Engine' ) ) {
	exit( "Run this with WP-CLI on a site where WooCommerce and this plugin are active.\n" );
}

add_filter( 'pre_wp_mail', '__return_true' );

$saved_rules    = get_option( 'wcdr_discount_rules', null );
$saved_settings = get_option( 'wcdr_settings', null );

$product = new WC_Product_Simple();
$product->set_name( 'WCDR smoke test item' );
$product->set_status( 'publish' );
$product->set_regular_price( 100 );
$product->set_price( 100 );
$product->save();
$pid = $product->get_id();

$failures = 0;
$check    = static function ( $label, $ok ) use ( &$failures ) {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n";
};
$rule     = static function ( $over = [] ) {
	WCDR_Rules_Manager::save_rule( array_merge( [ 'title' => 'Rule', 'status' => 'active', 'discount_type' => 'percentage', 'discount_value' => 10, 'apply_to' => 'all', 'apply_mode' => 'cart_fee', 'show_on_catalog' => 'yes' ], $over ) );
};
$reset    = static function () {
	delete_option( 'wcdr_discount_rules' );
};
$cart     = static function ( $qty = 1, $method = '' ) use ( $pid ) {
	wc_load_cart();
	WC()->cart->empty_cart();
	WC()->session->set( 'chosen_payment_method', $method );
	WC()->cart->add_to_cart( $pid, $qty );
	WC()->cart->calculate_totals();
	return WC()->cart;
};
$items    = static function ( $c ) {
	return (float) $c->get_subtotal();
};
$fees     = static function ( $c ) {
	return (float) array_sum( wp_list_pluck( $c->get_fees(), 'amount' ) );
};
$near     = static function ( $a, $b ) {
	return abs( $a - $b ) < 0.01;
};
$html     = static function () use ( $pid ) {
	return wp_strip_all_tags( html_entity_decode( wc_get_product( $pid )->get_price_html() ) );
};

delete_option( 'wcdr_settings' );

$reset(); $rule();
$check( '10% really lowers the price to 90', $near( $items( $cart() ), 90 ) );
$check( 'shop price shows $90 with the real $100 struck through', '$90.00$100.00(10% off)' === $html() );

$reset(); $rule( [ 'enable_payment_bonus' => 'yes', 'payment_bonus_value' => 5 ] );
foreach ( [ 'cod', 'bacs', 'cheque' ] as $method ) {
	$c = $cart( 1, $method );
	$check( "offline gateway $method gets no bonus (pays 90)", $near( $items( $c ) + $fees( $c ), 90 ) );
}
foreach ( [ 'stripe', 'paypal' ] as $method ) {
	$c = $cart( 1, $method );
	$check( "online gateway $method pays 90 - 5% = 85.50", $near( $items( $c ) + $fees( $c ), 85.5 ) );
}

$reset(); $rule( [ 'discount_type' => 'fixed', 'discount_value' => 50 ] );
$check( 'fixed 50 shows $50 with $100 struck through', '$50.00$100.00(50% off)' === $html() );
$reset(); $rule( [ 'discount_type' => 'fixed', 'discount_value' => 150 ] );
$check( 'fixed 150 never shows more than 100% off', false === strpos( $html(), '150' ) );

$reset(); $rule( [ 'min_cart_total' => 100 ] );
$c = $cart( 1, 'stripe' );
$prices = [];
for ( $i = 0; $i < 6; $i++ ) {
	$c->calculate_totals();
	$prices[] = round( (float) array_values( $c->get_cart() )[0]['data']->get_price(), 2 );
}
$check( 'minimum cart total: the price is the same on every recalculation', 1 === count( array_unique( $prices ) ) && $near( $prices[0], 90 ) );
$reset(); $rule( [ 'min_cart_total' => 150 ] );
$check( 'minimum 150 on a 100 cart: no discount', $near( $items( $cart() ), 100 ) );
$reset(); $rule( [ 'max_cart_total' => 150 ] );
$check( 'maximum 150 on a 100 cart: discount applies', $near( $items( $cart() ), 90 ) );

$reset(); $rule( [ 'show_on_catalog' => 'no', 'apply_mode' => 'cart_fee' ] );
$c = $cart();
$check( 'cart fee mode: price stays 100 and a -10 discount line is added', $near( $items( $c ), 100 ) && $near( $fees( $c ), -10 ) );
$reset(); $rule( [ 'show_on_catalog' => 'no', 'apply_mode' => 'item_price' ] );
$c = $cart();
$check( 'item price mode: price 90 and no discount line', $near( $items( $c ), 90 ) && $near( $fees( $c ), 0 ) );

$reset(); $rule( [ 'discount_type' => 'fixed', 'discount_value' => 10 ] );
$c = $cart( 3, 'stripe' );
ob_start();
WCDR_Frontend::instance()->display_cart_notice();
$notice = wp_strip_all_tags( html_entity_decode( ob_get_clean() ) );
$check( 'fixed 10 on 3 units saves 30 and the notice says so', $near( $items( $c ), 270 ) && false !== strpos( $notice, '30.00' ) );

$reset(); $rule( [ 'max_discount' => 4 ] );
$check( 'max discount 4 per item: shop price 96', '$96.00$100.00(4% off)' === $html() );
$c = $cart( 3 );
$check( 'max discount 4 per item: 3 units cost 288 in the cart', $near( $items( $c ), 288 ) );
$reset(); $rule( [ 'max_discount' => 4, 'show_on_catalog' => 'no', 'apply_mode' => 'cart_fee' ] );
$c = $cart( 3 );
$check( 'max discount 4 per item as a discount line: -12', $near( $fees( $c ), -12 ) );

$reset(); $rule( [ 'title' => 'R10', 'discount_value' => 10 ] ); $rule( [ 'title' => 'R20', 'discount_value' => 20 ] );
$check( 'two rules stack: 10% + 20% = 70', $near( $items( $cart() ), 70 ) );
$reset(); $rule( [ 'title' => 'Buy & Save', 'show_on_catalog' => 'no', 'apply_mode' => 'cart_fee' ] );
$fee_names = array_values( wp_list_pluck( $cart()->get_fees(), 'name' ) );
$check( 'a "&" in a discount line name is not escaped twice', ! empty( $fee_names ) && false === strpos( $fee_names[0], '&amp;' ) );

// Free version limit: 10 rules.
$reset();
for ( $i = 1; $i <= 10; $i++ ) {
	WCDR_Rules_Manager::save_rule( [ 'title' => "L$i", 'discount_type' => 'percentage', 'discount_value' => 1 ] );
}
$check( '10 rules can be saved in the free version', 10 === count( WCDR_Rules_Manager::get_rules( false ) ) );
$first = array_key_first( WCDR_Rules_Manager::get_rules( false ) );
if ( ! class_exists( 'WCDRP_Fields' ) ) { // The Pro add-on lifts the limit.
$over = WCDR_Rules_Manager::save_rule( [ 'title' => 'L11', 'discount_type' => 'percentage', 'discount_value' => 1 ] );
$check( 'the 11th rule is refused with an upgrade message', is_wp_error( $over ) && 'rule_limit' === $over->get_error_code() && 10 === count( WCDR_Rules_Manager::get_rules( false ) ) );
$dup   = WCDR_Rules_Manager::duplicate_rule( $first );
$check( 'duplicating at the limit is refused too', is_wp_error( $dup ) );
}
$edit = WCDR_Rules_Manager::get_rule( $first );
$edit['title'] = 'Edited';
$edit_id = WCDR_Rules_Manager::save_rule( $edit );
$check( 'editing an existing rule still works at the limit', ! is_wp_error( $edit_id ) && 'Edited' === WCDR_Rules_Manager::get_rule( $first )['title'] );

// Clean up.
wc_load_cart();
WC()->cart->empty_cart();
wp_delete_post( $pid, true );
null === $saved_rules ? delete_option( 'wcdr_discount_rules' ) : update_option( 'wcdr_discount_rules', $saved_rules );
null === $saved_settings ? delete_option( 'wcdr_settings' ) : update_option( 'wcdr_settings', $saved_settings );

echo "\n" . ( $failures ? "$failures check(s) FAILED\n" : "All checks passed\n" );
exit( $failures ? 1 : 0 );
