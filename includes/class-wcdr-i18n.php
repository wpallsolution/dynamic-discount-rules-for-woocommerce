<?php
/**
 * WPML support: translatable texts and rules that follow product and category translations.
 *
 * @package WCDR
 */

defined( 'ABSPATH' ) || exit;

class WCDR_I18n {

	const CONTEXT = 'dynamic-discount-rules-for-woocommerce';

	public static function init() {
		add_action( 'admin_init', [ __CLASS__, 'maybe_register_all' ] );
	}

	/** Is WPML (with String Translation) active? */
	public static function is_active() {
		return (bool) has_filter( 'wpml_translate_single_string' );
	}

	/**
	 * Register one text with WPML String Translation.
	 */
	public static function register( $name, $value ) {
		if ( self::is_active() && '' !== (string) $value ) {
			do_action( 'wpml_register_single_string', self::CONTEXT, $name, $value ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook provided by WPML.
		}
	}

	/** Register the texts of one rule. */
	public static function register_rule( $rule ) {
		if ( empty( $rule['id'] ) ) {
			return;
		}
		self::register( 'rule_' . $rule['id'] . '_title', $rule['title'] ?? '' );
		self::register( 'rule_' . $rule['id'] . '_bonus_label', $rule['payment_bonus_label'] ?? '' );
	}

	/** Register every rule and the cart notice, once per change, when an admin screen loads. */
	public static function maybe_register_all() {
		if ( ! self::is_active() ) {
			return;
		}

		$rules    = WCDR_Rules_Manager::get_rules( false );
		$settings = WCDR_Rules_Manager::get_default_settings();
		$hash     = md5( wp_json_encode( [ wp_list_pluck( $rules, 'title' ), wp_list_pluck( $rules, 'payment_bonus_label' ), $settings['cart_notice_text'] ?? '' ] ) );

		if ( get_option( 'wcdr_i18n_hash' ) === $hash ) {
			return;
		}

		foreach ( $rules as $rule ) {
			self::register_rule( $rule );
		}
		self::register( 'cart_notice_text', $settings['cart_notice_text'] ?? '' );

		update_option( 'wcdr_i18n_hash', $hash, false );
	}

	/**
	 * A registered text in the current language. Falls back to the original text.
	 *
	 * @param string $name  Registered string name.
	 * @param string $value Original text.
	 * @return string
	 */
	public static function translate( $name, $value ) {
		if ( ! self::is_active() || '' === (string) $value ) {
			return $value;
		}

		$translated = apply_filters( 'wpml_translate_single_string', $value, self::CONTEXT, $name ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook provided by WPML.
		if ( $translated !== $value ) {
			return $translated;
		}

		// The filter reads compiled translation files; fall back to the saved translation so a text that
		// was just translated shows up before String Translation has compiled it.
		$target = apply_filters( 'wpml_current_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook provided by WPML.
		if ( $target && $target !== apply_filters( 'wpml_default_language', null ) && function_exists( 'icl_get_string_id' ) && function_exists( 'icl_get_string_by_id' ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook provided by WPML.
			$string_id = icl_get_string_id( $value, self::CONTEXT, $name );
			$stored    = $string_id ? icl_get_string_by_id( $string_id, $target ) : '';
			if ( is_string( $stored ) && '' !== $stored ) {
				return $stored;
			}
		}

		return $value;
	}

	/** Rule title in the current language. */
	public static function rule_title( $rule ) {
		return self::translate( 'rule_' . ( $rule['id'] ?? '' ) . '_title', $rule['title'] ?? '' );
	}

	/** Payment bonus label in the current language. */
	public static function bonus_label( $rule, $label ) {
		return self::translate( 'rule_' . ( $rule['id'] ?? '' ) . '_bonus_label', $label );
	}

	/**
	 * The same post or term in the site's default language, so a rule made for the original product
	 * also applies to its translations. Without WPML the ID is returned unchanged.
	 *
	 * @param int    $id   Post or term ID.
	 * @param string $type Element type: product, product_variation or product_cat.
	 * @return int
	 */
	public static function default_id( $id, $type ) {
		$id = absint( $id );
		if ( ! $id || ! has_filter( 'wpml_object_id' ) ) {
			return $id;
		}

		$default = apply_filters( 'wpml_default_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook provided by WPML.
		$mapped  = apply_filters( 'wpml_object_id', $id, $type, true, $default ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook provided by WPML.

		return $mapped ? absint( $mapped ) : $id;
	}

	/**
	 * Default-language IDs of a list, together with the IDs it already had.
	 *
	 * @param array  $ids  IDs.
	 * @param string $type Element type.
	 * @return int[]
	 */
	public static function with_default_ids( $ids, $type ) {
		$out = [];
		foreach ( (array) $ids as $id ) {
			$id    = absint( $id );
			$out[] = $id;
			$out[] = self::default_id( $id, $type );
		}
		return array_values( array_unique( array_filter( $out ) ) );
	}
}
