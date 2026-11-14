<?php
/**
 * Admin Interface and Settings Class.
 *
 * Provides a modern, responsive interface for managing discount rules and options.
 *
 * @package WCDR
 */

defined( 'ABSPATH' ) || exit;

class WCDR_Admin {

	/**
	 * Singleton instance.
	 *
	 * @var WCDR_Admin|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return WCDR_Admin
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
		add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		add_action( 'admin_init', [ $this, 'handle_form_submissions' ] );
		add_filter( 'woocommerce_screen_ids', [ $this, 'add_woocommerce_screen_id' ] );
		add_action( 'current_screen', [ $this, 'remove_help_tab' ], 99 );
	}

	/**
	 * Register WooCommerce screen ID so core WC styles and scripts bind properly.
	 *
	 * @param array $screen_ids
	 * @return array
	 */
	public function add_woocommerce_screen_id( $screen_ids ) {
		$screen_ids[] = 'woocommerce_page_wcdr-discount-rules';
		return $screen_ids;
	}

	/**
	 * Register WooCommerce submenu page for Discount Rules.
	 */
	public function register_admin_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Discount Rules', 'dynamic-discount-rules-for-woocommerce' ),
			__( 'Discount Rules', 'dynamic-discount-rules-for-woocommerce' ),
			'manage_woocommerce',
			'wcdr-discount-rules',
			[ $this, 'render_admin_page' ]
		);
	}

	/**
	 * The plugin has its own Help tab, so the default WordPress "Help" button is not shown on its screen.
	 * It runs late because WooCommerce adds its help tabs on the same hook.
	 *
	 * @param WP_Screen $screen Current screen.
	 */
	public function remove_help_tab( $screen ) {
		if ( $screen && 'woocommerce_page_wcdr-discount-rules' === $screen->id ) {
			$screen->remove_help_tabs();
		}
	}

	/**
	 * Enqueue admin CSS and JavaScript.
	 *
	 * @param string $hook
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( 'woocommerce_page_wcdr-discount-rules' !== $hook ) {
			return;
		}

		// Enqueue WooCommerce core admin styles and Select2 / selectWoo
		if ( wp_style_is( 'woocommerce_admin_styles', 'registered' ) ) {
			wp_enqueue_style( 'woocommerce_admin_styles' );
		}
		if ( wp_script_is( 'wc-enhanced-select', 'registered' ) ) {
			wp_enqueue_script( 'wc-enhanced-select' );
		}
		if ( wp_script_is( 'selectWoo', 'registered' ) ) {
			wp_enqueue_script( 'selectWoo' );
		} elseif ( wp_script_is( 'select2', 'registered' ) ) {
			wp_enqueue_script( 'select2' );
		}

		// Enqueue plugin admin CSS
		wp_enqueue_style(
			'wcdr-admin',
			WCDR_PLUGIN_URL . 'assets/css/admin.css',
			[],
			WCDR_VERSION
		);

		// Enqueue plugin admin JS
		wp_enqueue_script(
			'wcdr-admin',
			WCDR_PLUGIN_URL . 'assets/js/admin.js',
			[ 'jquery' ],
			WCDR_VERSION,
			true
		);

		wp_localize_script(
			'wcdr-admin',
			'wcdr_admin_params',
			[
				'ajax_url'        => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'wcdr_admin_nonce' ),
				'currency_symbol' => function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$',
				'i18n'            => [
					'confirm_delete' => __( 'Are you sure you want to delete this discount rule? This action cannot be undone.', 'dynamic-discount-rules-for-woocommerce' ),
					'rule_duplicated'=> __( 'Rule duplicated successfully! Refreshing...', 'dynamic-discount-rules-for-woocommerce' ),
					'searching'      => __( 'Searching products...', 'dynamic-discount-rules-for-woocommerce' ),
					'no_products'    => __( 'No products found.', 'dynamic-discount-rules-for-woocommerce' ),
					'active'         => __( 'Active', 'dynamic-discount-rules-for-woocommerce' ),
					'inactive'       => __( 'Inactive', 'dynamic-discount-rules-for-woocommerce' ),
				],
			]
		);
	}

	/**
	 * Handle POST submissions for rule save and settings save.
	 */
	public function handle_form_submissions() {
		if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Handle Save Rule
		if ( isset( $_POST['wcdr_action'] ) && 'save_rule' === $_POST['wcdr_action'] ) {
			check_admin_referer( 'wcdr_save_rule_nonce', 'wcdr_nonce' );

			$rule_id = WCDR_Rules_Manager::save_rule( wp_unslash( $_POST ) );

			if ( is_wp_error( $rule_id ) ) {
				$redirect_url = add_query_arg(
					[
						'page'   => 'wcdr-discount-rules',
						'tab'    => 'add',
						'status' => 'error',
						'msg'    => rawurlencode( $rule_id->get_error_message() ),
					],
					admin_url( 'admin.php' )
				);
			} else {
				$redirect_url = add_query_arg(
					[
						'page'   => 'wcdr-discount-rules',
						'status' => 'saved',
					],
					admin_url( 'admin.php' )
				);
			}

			wp_safe_redirect( $redirect_url );
			exit;
		}

		// Handle Save Global Settings
		if ( isset( $_POST['wcdr_action'] ) && 'save_settings' === $_POST['wcdr_action'] ) {
			check_admin_referer( 'wcdr_save_settings_nonce', 'wcdr_nonce' );

			WCDR_Rules_Manager::save_settings( wp_unslash( $_POST ) );

			$redirect_url = add_query_arg(
				[
					'page'   => 'wcdr-discount-rules',
					'tab'    => 'settings',
					'status' => 'settings_saved',
				],
				admin_url( 'admin.php' )
			);

			wp_safe_redirect( $redirect_url );
			exit;
		}

		// Handle Direct Delete Action from URL
		if ( isset( $_GET['page'], $_GET['action'], $_GET['rule_id'], $_GET['_wpnonce'] ) && 'wcdr-discount-rules' === $_GET['page'] && 'delete' === $_GET['action'] ) {
			if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wcdr_delete_rule_' . sanitize_key( $_GET['rule_id'] ) ) ) {
				WCDR_Rules_Manager::delete_rule( sanitize_key( $_GET['rule_id'] ) );
				wp_safe_redirect( add_query_arg( [ 'page' => 'wcdr-discount-rules', 'status' => 'deleted' ], admin_url( 'admin.php' ) ) );
				exit;
			}
		}
	}

	/**
	 * Render the main plugin admin page.
	 */
	public function render_admin_page() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'rules'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects which screen is shown.
		$rule_id = isset( $_GET['rule_id'] ) ? sanitize_key( wp_unslash( $_GET['rule_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects which rule is shown.

		?>
		<div class="wrap wcdr-admin-wrapper">
			<h1 class="screen-reader-text"><?php esc_html_e( 'Dynamic Discount Rules', 'dynamic-discount-rules-for-woocommerce' ); ?></h1>
			<?php
			$rules_count = count( WCDR_Rules_Manager::get_rules( false ) );
			$base_url    = admin_url( 'admin.php?page=wcdr-discount-rules' );
			$is_form     = in_array( $tab, [ 'add', 'edit' ], true );
			?>
			<!-- Top bar -->
			<div class="wcdr-topbar">
				<div class="wcdr-brand">
					<span class="wcdr-logo dashicons dashicons-tag"></span>
					<span class="wcdr-brand-name"><?php esc_html_e( 'Discount Rules', 'dynamic-discount-rules-for-woocommerce' ); ?></span>
					<span class="wcdr-version">v<?php echo esc_html( WCDR_VERSION ); ?></span>
				</div>
				<nav class="wcdr-tabs" aria-label="<?php esc_attr_e( 'Discount Rules sections', 'dynamic-discount-rules-for-woocommerce' ); ?>">
					<a href="<?php echo esc_url( $base_url ); ?>" class="wcdr-tab <?php echo ( 'rules' === $tab || $is_form ) ? 'is-active' : ''; ?>">
						<span class="dashicons dashicons-tag"></span><?php esc_html_e( 'Rules', 'dynamic-discount-rules-for-woocommerce' ); ?>
						<span class="wcdr-count"><?php echo esc_html( $rules_count ); ?></span>
					</a>
					<a href="<?php echo esc_url( $base_url . '&tab=settings' ); ?>" class="wcdr-tab <?php echo 'settings' === $tab ? 'is-active' : ''; ?>">
						<span class="dashicons dashicons-admin-settings"></span><?php esc_html_e( 'Settings', 'dynamic-discount-rules-for-woocommerce' ); ?>
					</a>
					<a href="<?php echo esc_url( $base_url . '&tab=help' ); ?>" class="wcdr-tab <?php echo 'help' === $tab ? 'is-active' : ''; ?>">
						<span class="dashicons dashicons-book"></span><?php esc_html_e( 'Help', 'dynamic-discount-rules-for-woocommerce' ); ?>
					</a>
				</nav>
				<div class="wcdr-topbar-actions">
					<?php if ( $is_form ) : ?>
						<a href="<?php echo esc_url( $base_url ); ?>" class="wcdr-btn wcdr-btn-ghost"><?php esc_html_e( 'Cancel', 'dynamic-discount-rules-for-woocommerce' ); ?></a>
						<button type="submit" form="wcdr-rule-edit-form" class="wcdr-btn wcdr-btn-primary"><?php echo 'edit' === $tab ? esc_html__( 'Update rule', 'dynamic-discount-rules-for-woocommerce' ) : esc_html__( 'Save rule', 'dynamic-discount-rules-for-woocommerce' ); ?></button>
					<?php elseif ( 'rules' === $tab && WCDR_Rules_Manager::limit_reached( $rules_count ) ) : ?>
						<a href="<?php echo esc_url( WCDR_Rules_Manager::upgrade_url() ); ?>" target="_blank" rel="noopener noreferrer" class="wcdr-btn wcdr-btn-primary"><span class="dashicons dashicons-star-filled"></span><?php esc_html_e( 'Upgrade to Pro', 'dynamic-discount-rules-for-woocommerce' ); ?></a>
					<?php elseif ( 'rules' === $tab ) : ?>
						<a href="<?php echo esc_url( $base_url . '&tab=add' ); ?>" class="wcdr-btn wcdr-btn-primary"><span class="dashicons dashicons-plus-alt2"></span><?php esc_html_e( 'Add rule', 'dynamic-discount-rules-for-woocommerce' ); ?></a>
					<?php endif; ?>
				</div>
			</div>

			<!-- Admin Notices -->
			<?php $this->render_notices(); ?>

			<!-- Tab Content -->
			<div class="wcdr-content-container">
				<?php
				switch ( $tab ) {
					case 'add':
					case 'edit':
						$this->render_rule_form( $rule_id );
						break;
					case 'settings':
						$this->render_settings_tab();
						break;
					case 'help':
						$this->render_help_tab();
						break;
					case 'rules':
					default:
						$this->render_rules_list();
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render system feedback notices.
	 */
	private function render_notices() {
		if ( empty( $_GET['status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$status = sanitize_key( wp_unslash( $_GET['status'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg    = ! empty( $_GET['msg'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['msg'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( 'saved' === $status ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Discount rule saved successfully.', 'dynamic-discount-rules-for-woocommerce' ) . '</p></div>';
		} elseif ( 'deleted' === $status ) {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'Discount rule deleted successfully.', 'dynamic-discount-rules-for-woocommerce' ) . '</p></div>';
		} elseif ( 'settings_saved' === $status ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings updated successfully.', 'dynamic-discount-rules-for-woocommerce' ) . '</p></div>';
		} elseif ( 'error' === $status && ! empty( $msg ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
	}

	/**
	 * Render Rules List Table.
	 */
	private function render_rules_list() {
		$rules        = WCDR_Rules_Manager::get_rules( true );
		$total_rules  = count( $rules );
		$active_count = 0;

		foreach ( $rules as $r ) {
			if ( 'active' === ( $r['status'] ?? 'active' ) ) {
				$active_count++;
			}
		}

		$categories = WCDR_Rules_Manager::get_all_categories();
		$gateways   = WCDR_Rules_Manager::get_all_payment_gateways();
		$roles      = WCDR_Rules_Manager::get_all_user_roles();

		?>
		<?php if ( WCDR_Rules_Manager::limit_reached( $total_rules ) ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<?php
					printf(
						/* translators: %d: maximum number of rules in the free version */
						esc_html__( 'You have reached the free limit of %d rules. Upgrade to Pro for unlimited rules, quantity discounts, BOGO, scheduling and more.', 'dynamic-discount-rules-for-woocommerce' ),
						(int) WCDR_Rules_Manager::rule_limit()
					);
					?>
					<a href="<?php echo esc_url( WCDR_Rules_Manager::upgrade_url() ); ?>" target="_blank" rel="noopener noreferrer"><strong><?php esc_html_e( 'See Pro features', 'dynamic-discount-rules-for-woocommerce' ); ?></strong></a>
				</p>
			</div>
		<?php endif; ?>

		<?php if ( empty( $rules ) ) : ?>
			<div class="wcdr-empty-state">
				<span class="dashicons dashicons-tag wcdr-empty-icon"></span>
				<h2><?php esc_html_e( 'No Discount Rules Created Yet', 'dynamic-discount-rules-for-woocommerce' ); ?></h2>
				<p><?php esc_html_e( 'Create category discounts, product offers, cart total min/max rules, user role specials, and payment gateway discounts in seconds.', 'dynamic-discount-rules-for-woocommerce' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wcdr-discount-rules&tab=add' ) ); ?>" class="wcdr-btn wcdr-btn-primary">
					<?php esc_html_e( 'Create your first rule', 'dynamic-discount-rules-for-woocommerce' ); ?>
				</a>
			</div>
		<?php else : ?>
			<div class="wcdr-rule-list">
				<?php
				foreach ( $rules as $rule_id => $rule ) :
					$is_active = ( 'active' === ( $rule['status'] ?? 'active' ) );
					$edit_url  = admin_url( 'admin.php?page=wcdr-discount-rules&tab=edit&rule_id=' . esc_attr( $rule_id ) );
					$del_nonce = wp_create_nonce( 'wcdr_delete_rule_' . $rule_id );
					$del_url   = admin_url( 'admin.php?page=wcdr-discount-rules&action=delete&rule_id=' . esc_attr( $rule_id ) . '&_wpnonce=' . $del_nonce );
					$apply_to  = $rule['apply_to'] ?? 'all';

					// One line that says what the rule does.
					$summary = ( 'percentage' === ( $rule['discount_type'] ?? 'percentage' ) )
						? esc_html( $rule['discount_value'] ) . '% ' . esc_html__( 'off', 'dynamic-discount-rules-for-woocommerce' )
						: wp_kses_post( wc_price( $rule['discount_value'] ) ) . ' ' . esc_html__( 'off', 'dynamic-discount-rules-for-woocommerce' );
					?>
					<div class="wcdr-rule-row<?php echo $is_active ? '' : ' is-inactive'; ?>" id="rule-row-<?php echo esc_attr( $rule_id ); ?>" data-rule-id="<?php echo esc_attr( $rule_id ); ?>">
						<label class="wcdr-switch">
							<input type="checkbox" class="wcdr-toggle-status" data-rule-id="<?php echo esc_attr( $rule_id ); ?>" <?php checked( $is_active, true ); ?>>
							<span class="wcdr-slider round"></span>
						</label>

						<div class="wcdr-rule-main">
							<a href="<?php echo esc_url( $edit_url ); ?>" class="row-title"><?php echo esc_html( $rule['title'] ); ?></a>
							<div class="wcdr-rule-sub">
								<span class="wcdr-discount-tag"><?php echo $summary; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?></span>
								<?php if ( ! empty( $rule['max_discount'] ) ) : ?>
									<span><?php /* translators: %s: maximum discount amount per item */ printf( esc_html__( 'max %s per item', 'dynamic-discount-rules-for-woocommerce' ), wp_kses_post( wc_price( $rule['max_discount'] ) ) ); ?></span>
								<?php endif; ?>
								<span><?php echo ( 'item_price' === ( $rule['apply_mode'] ?? 'cart_fee' ) ) ? esc_html__( 'Item price', 'dynamic-discount-rules-for-woocommerce' ) : esc_html__( 'Cart discount line', 'dynamic-discount-rules-for-woocommerce' ); ?></span>
								<?php if ( 10 !== (int) ( $rule['priority'] ?? 10 ) ) : ?>
									<span><?php /* translators: %d: rule priority */ echo esc_html( sprintf( __( 'Priority %d', 'dynamic-discount-rules-for-woocommerce' ), (int) $rule['priority'] ) ); ?></span>
								<?php endif; ?>
							</div>
						</div>

						<div class="wcdr-badge-list">
							<?php
							if ( 'all' === $apply_to ) {
								echo '<span class="wcdr-chip wcdr-chip-blue">' . esc_html__( 'All products', 'dynamic-discount-rules-for-woocommerce' ) . '</span>';
							} elseif ( 'categories' === $apply_to || 'both' === $apply_to ) {
								if ( empty( $rule['include_categories'] ) && 'categories' === $apply_to ) {
									echo '<span class="wcdr-chip wcdr-chip-red">' . esc_html__( 'No category chosen', 'dynamic-discount-rules-for-woocommerce' ) . '</span>';
								} elseif ( ! empty( $rule['include_categories'] ) ) {
									/* translators: %d: number of categories */
									echo '<span class="wcdr-chip wcdr-chip-purple">' . esc_html( sprintf( _n( '%d category', '%d categories', count( $rule['include_categories'] ), 'dynamic-discount-rules-for-woocommerce' ), count( $rule['include_categories'] ) ) ) . '</span>';
								}
							}
							if ( 'products' === $apply_to || 'both' === $apply_to ) {
								if ( empty( $rule['include_products'] ) && 'products' === $apply_to ) {
									echo '<span class="wcdr-chip wcdr-chip-red">' . esc_html__( 'No product chosen', 'dynamic-discount-rules-for-woocommerce' ) . '</span>';
								} elseif ( ! empty( $rule['include_products'] ) ) {
									/* translators: %d: number of products */
									echo '<span class="wcdr-chip wcdr-chip-purple">' . esc_html( sprintf( _n( '%d product', '%d products', count( $rule['include_products'] ), 'dynamic-discount-rules-for-woocommerce' ), count( $rule['include_products'] ) ) ) . '</span>';
								}
							}
							if ( ! empty( $rule['exclude_categories'] ) || ! empty( $rule['exclude_products'] ) ) {
								echo '<span class="wcdr-chip wcdr-chip-red">' . esc_html__( 'Has exclusions', 'dynamic-discount-rules-for-woocommerce' ) . '</span>';
							}
							if ( ! empty( $rule['min_cart_total'] ) || ! empty( $rule['max_cart_total'] ) ) {
								echo '<span class="wcdr-chip wcdr-chip-gray">' . esc_html__( 'Cart total', 'dynamic-discount-rules-for-woocommerce' ) . '</span>';
							}
							if ( ! empty( $rule['user_roles'] ) && ! in_array( 'all', $rule['user_roles'], true ) ) {
								echo '<span class="wcdr-chip wcdr-chip-gray">' . esc_html( implode( ', ', array_map( 'ucfirst', $rule['user_roles'] ) ) ) . '</span>';
							}
							if ( ! empty( $rule['payment_methods'] ) && ! in_array( 'all', $rule['payment_methods'], true ) ) {
								echo '<span class="wcdr-chip wcdr-chip-gray">' . esc_html( implode( ', ', array_map( 'strtoupper', $rule['payment_methods'] ) ) ) . '</span>';
							}
							if ( 'yes' === ( $rule['enable_payment_bonus'] ?? 'no' ) ) {
								echo '<span class="wcdr-chip wcdr-chip-green">' . esc_html__( 'Payment bonus', 'dynamic-discount-rules-for-woocommerce' ) . '</span>';
							}

							/**
							 * Lets add-ons add chips to the rules list.
							 *
							 * @param array $rule Rule.
							 */
							do_action( 'wcdr_rule_list_chips', $rule );
							?>
						</div>

						<span class="wcdr-status-pill <?php echo $is_active ? 'is-active' : 'is-inactive'; ?>" data-active="<?php esc_attr_e( 'Active', 'dynamic-discount-rules-for-woocommerce' ); ?>" data-inactive="<?php esc_attr_e( 'Inactive', 'dynamic-discount-rules-for-woocommerce' ); ?>">
							<?php echo $is_active ? esc_html__( 'Active', 'dynamic-discount-rules-for-woocommerce' ) : esc_html__( 'Inactive', 'dynamic-discount-rules-for-woocommerce' ); ?>
						</span>

						<div class="wcdr-action-buttons">
							<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small" title="<?php esc_attr_e( 'Edit Rule', 'dynamic-discount-rules-for-woocommerce' ); ?>">
								<span class="dashicons dashicons-edit"></span>
							</a>
							<button type="button" class="button button-small wcdr-duplicate-btn" data-rule-id="<?php echo esc_attr( $rule_id ); ?>" title="<?php esc_attr_e( 'Duplicate Rule', 'dynamic-discount-rules-for-woocommerce' ); ?>">
								<span class="dashicons dashicons-admin-page"></span>
							</button>
							<a href="<?php echo esc_url( $del_url ); ?>" class="button button-small button-link-delete wcdr-delete-rule" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete this rule?', 'dynamic-discount-rules-for-woocommerce' ); ?>');" title="<?php esc_attr_e( 'Delete Rule', 'dynamic-discount-rules-for-woocommerce' ); ?>">
								<span class="dashicons dashicons-trash"></span>
							</a>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render Add / Edit Rule Form.
	 *
	 * @param string $rule_id
	 */
	private function render_rule_form( $rule_id = '' ) {
		$rule = ! empty( $rule_id ) ? WCDR_Rules_Manager::get_rule( $rule_id ) : null;
		if ( ! $rule ) {
			$rule = WCDR_Rules_Manager::get_default_rule();
		}

		$all_categories = WCDR_Rules_Manager::get_all_categories();
		$all_gateways   = WCDR_Rules_Manager::get_all_payment_gateways();
		$all_roles      = WCDR_Rules_Manager::get_all_user_roles();
		$product_types  = WCDR_Rules_Manager::get_all_product_types();

		$is_edit = ! empty( $rule_id );
		?>
		<form method="post" action="" class="wcdr-form" id="wcdr-rule-edit-form">
			<?php wp_nonce_field( 'wcdr_save_rule_nonce', 'wcdr_nonce' ); ?>
			<input type="hidden" name="wcdr_action" value="save_rule">
			<input type="hidden" name="id" value="<?php echo esc_attr( $rule['id'] ); ?>">

			<div class="wcdr-form-layout">
			<div class="wcdr-form-main">
			<div class="wcdr-rule-card">
				<div class="wcdr-rule-head">
					<div class="wcdr-rule-name">
							<div class="wcdr-form-group">
								<label for="wcdr_title" class="wcdr-label">
									<?php esc_html_e( 'Rule Title', 'dynamic-discount-rules-for-woocommerce' ); ?> <span class="wcdr-required">*</span>
								</label>
								<input type="text" id="wcdr_title" name="title" class="regular-text wcdr-input-full" value="<?php echo esc_attr( $rule['title'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. 10% Off on Summer Clothing', 'dynamic-discount-rules-for-woocommerce' ); ?>" required>
								<p class="description"><?php esc_html_e( 'Shown in the cart and checkout totals breakdown when applied.', 'dynamic-discount-rules-for-woocommerce' ); ?></p>
							</div>
					</div>
				</div>

				<nav class="wcdr-subtabs" role="tablist"></nav>

				<div class="wcdr-panels">

				<section class="wcdr-panel" id="wcdr-panel-discount" data-title="<?php echo esc_attr__( 'Discount', 'dynamic-discount-rules-for-woocommerce' ); ?>" data-icon="tag">

					<div class="wcdr-section">
						<div class="wcdr-section-info">
							<h4><?php esc_html_e( 'Discount', 'dynamic-discount-rules-for-woocommerce' ); ?></h4>
							<p><?php esc_html_e( 'How much is taken off each item.', 'dynamic-discount-rules-for-woocommerce' ); ?></p>
						</div>
						<div class="wcdr-section-fields">
<div class="wcdr-grid-2">
								<div class="wcdr-form-group">
									<label for="wcdr_discount_type" class="wcdr-label"><?php esc_html_e( 'Discount Type', 'dynamic-discount-rules-for-woocommerce' ); ?></label>
									<select id="wcdr_discount_type" name="discount_type" class="wcdr-select">
										<option value="percentage" <?php selected( $rule['discount_type'], 'percentage' ); ?>>
											<?php esc_html_e( 'Percentage Discount (%)', 'dynamic-discount-rules-for-woocommerce' ); ?>
										</option>
										<option value="fixed" <?php selected( $rule['discount_type'], 'fixed' ); ?>>
											<?php /* translators: %s: currency symbol */ echo esc_html( sprintf( __( 'Fixed Amount Discount (%s)', 'dynamic-discount-rules-for-woocommerce' ), function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$' ) ); ?>
										</option>
									</select>
								</div>
								<div class="wcdr-form-group">
									<label for="wcdr_discount_value" class="wcdr-label">
										<?php esc_html_e( 'Discount Value', 'dynamic-discount-rules-for-woocommerce' ); ?> <span class="wcdr-required">*</span>
									</label>
									<div class="wcdr-input-group">
										<input type="number" step="0.01" min="0" id="wcdr_discount_value" name="discount_value" class="wcdr-input" value="<?php echo esc_attr( $rule['discount_value'] ); ?>" required>
										<span class="wcdr-input-addon" id="wcdr_discount_addon">
											<?php echo ( 'percentage' === $rule['discount_type'] ) ? '%' : esc_html( function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$' ); ?>
										</span>
									</div>
									<p class="description"><?php esc_html_e( 'For percentage, enter numbers like 10, 15, 20.5.', 'dynamic-discount-rules-for-woocommerce' ); ?></p>
								</div>
</div>
								<div class="wcdr-form-group">
									<label for="wcdr_max_discount" class="wcdr-label"><?php esc_html_e( 'Maximum Discount Cap', 'dynamic-discount-rules-for-woocommerce' ); ?></label>
									<div class="wcdr-input-group">
										<span class="wcdr-input-addon"><?php echo esc_html( function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$' ); ?></span>
										<input type="number" step="0.01" min="0" id="wcdr_max_discount" name="max_discount" class="wcdr-input" value="<?php echo ! empty( $rule['max_discount'] ) ? esc_attr( $rule['max_discount'] ) : ''; ?>" placeholder="<?php esc_attr_e( 'Unlimited', 'dynamic-discount-rules-for-woocommerce' ); ?>">
									</div>
									<p class="description"><?php esc_html_e( 'The most that is taken off one item. Leave empty or 0 for no cap.', 'dynamic-discount-rules-for-woocommerce' ); ?></p>
								</div>
						</div>
					</div>

					<div class="wcdr-section">
						<div class="wcdr-section-info">
							<h4><?php esc_html_e( 'Where it shows', 'dynamic-discount-rules-for-woocommerce' ); ?></h4>
							<p><?php esc_html_e( 'Choose how the customer sees the discount.', 'dynamic-discount-rules-for-woocommerce' ); ?></p>
						</div>
						<div class="wcdr-section-fields">
								<div class="wcdr-form-group">
									<label for="wcdr_apply_mode" class="wcdr-label"><?php esc_html_e( 'Application Method', 'dynamic-discount-rules-for-woocommerce' ); ?></label>
									<select id="wcdr_apply_mode" name="apply_mode" class="wcdr-select">
										<option value="cart_fee" <?php selected( $rule['apply_mode'], 'cart_fee' ); ?>>
											<?php esc_html_e( 'Cart Total Fee (shown as a discount line in the totals)', 'dynamic-discount-rules-for-woocommerce' ); ?>
										</option>
										<option value="item_price" <?php selected( $rule['apply_mode'], 'item_price' ); ?>>
											<?php esc_html_e( 'Line Item Price (Directly adjusts product prices in cart)', 'dynamic-discount-rules-for-woocommerce' ); ?>
										</option>
									</select>
									<p class="description"><?php esc_html_e( 'A rule that is shown on shop pages always changes the item price. "Cart Total Fee" is used when the shop display below is switched off.', 'dynamic-discount-rules-for-woocommerce' ); ?></p>
								</div>
							<div class="wcdr-form-group wcdr-option-box">
								<label class="wcdr-checkbox-label">
									<input type="checkbox" name="show_on_catalog" value="yes" <?php checked( $rule['show_on_catalog'] ?? 'yes', 'yes' ); ?>>
									<?php esc_html_e( 'Display Discount on Shop & Product Pages (Strikethrough Regular Price)', 'dynamic-discount-rules-for-woocommerce' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'Immediately displays discounted price, strikethrough regular price, and discount badge (for example ₹540 with ₹600 struck through and a 10% off badge) across Shop, Archives, and Single Product pages.', 'dynamic-discount-rules-for-woocommerce' ); ?>
								</p>
							</div>
						</div>
					</div>

				</section>

				<section class="wcdr-panel" id="wcdr-panel-products" data-title="<?php echo esc_attr__( 'Products', 'dynamic-discount-rules-for-woocommerce' ); ?>" data-icon="category">

					<div class="wcdr-section">
						<div class="wcdr-section-info">
							<h4><?php esc_html_e( 'Applies to', 'dynamic-discount-rules-for-woocommerce' ); ?></h4>
							<p><?php esc_html_e( 'Pick the products this rule works for.', 'dynamic-discount-rules-for-woocommerce' ); ?></p>
						</div>
						<div class="wcdr-section-fields">
							<div class="wcdr-form-group">
								<label for="wcdr_apply_to" class="wcdr-label"><?php esc_html_e( 'Target Scope', 'dynamic-discount-rules-for-woocommerce' ); ?></label>
								<select id="wcdr_apply_to" name="apply_to" class="wcdr-select">
									<option value="all" <?php selected( $rule['apply_to'], 'all' ); ?>>
										<?php esc_html_e( 'All Products (Store-wide)', 'dynamic-discount-rules-for-woocommerce' ); ?>
									</option>
									<option value="categories" <?php selected( $rule['apply_to'], 'categories' ); ?>>
										<?php esc_html_e( 'Specific Categories Only', 'dynamic-discount-rules-for-woocommerce' ); ?>
									</option>
									<option value="products" <?php selected( $rule['apply_to'], 'products' ); ?>>
										<?php esc_html_e( 'Specific Products Only', 'dynamic-discount-rules-for-woocommerce' ); ?>
									</option>
									<option value="both" <?php selected( $rule['apply_to'], 'both' ); ?>>
										<?php esc_html_e( 'Both Categories OR Products', 'dynamic-discount-rules-for-woocommerce' ); ?>
									</option>
								</select>
							</div>
							<div class="wcdr-form-group wcdr-scope-field wcdr-field-categories">
								<label for="wcdr_include_categories" class="wcdr-label"><?php esc_html_e( 'Include Categories (Multi-select)', 'dynamic-discount-rules-for-woocommerce' ); ?></label>
								<select id="wcdr_include_categories" name="include_categories[]" class="wcdr-select2 wc-enhanced-select" multiple="multiple" style="width: 100%;" data-placeholder="<?php esc_attr_e( 'Select categories to include...', 'dynamic-discount-rules-for-woocommerce' ); ?>">
									<?php foreach ( $all_categories as $cat_id => $cat_name ) : ?>
										<option value="<?php echo esc_attr( $cat_id ); ?>" <?php echo in_array( $cat_id, (array) $rule['include_categories'], true ) ? 'selected' : ''; ?>>
											<?php echo esc_html( $cat_name ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Select one or more categories that are eligible for this discount.', 'dynamic-discount-rules-for-woocommerce' ); ?></p>
							</div>
							<div class="wcdr-form-group wcdr-scope-field wcdr-field-products">
								<label for="wcdr_include_products" class="wcdr-label"><?php esc_html_e( 'Include Specific Products (Multi-select)', 'dynamic-discount-rules-for-woocommerce' ); ?></label>
								<select id="wcdr_include_products" name="include_products[]" class="wcdr-product-search wc-enhanced-select" multiple="multiple" style="width: 100%;" data-placeholder="<?php esc_attr_e( 'Search products by title or SKU...', 'dynamic-discount-rules-for-woocommerce' ); ?>">
									<?php
									if ( ! empty( $rule['include_products'] ) ) {
										foreach ( (array) $rule['include_products'] as $pid ) {
											$p = wc_get_product( $pid );
											if ( $p ) {
												echo '<option value="' . esc_attr( $pid ) . '" selected>#' . esc_html( $pid ) . ' ' . esc_html( $p->get_name() ) . '</option>';
											}
										}
									}
									?>
								</select>
								<p class="description"><?php esc_html_e( 'Type 2 or more characters to search and select products.', 'dynamic-discount-rules-for-woocommerce' ); ?></p>
							</div>
						</div>
					</div>

					<div class="wcdr-section">
						<div class="wcdr-section-info">
							<h4><?php esc_html_e( 'Exclusions', 'dynamic-discount-rules-for-woocommerce' ); ?></h4>
							<p><?php esc_html_e( 'These are never discounted, even when they match above.', 'dynamic-discount-rules-for-woocommerce' ); ?></p>
						</div>
						<div class="wcdr-section-fields">
							<div class="wcdr-form-group">
								<label for="wcdr_exclude_categories" class="wcdr-label"><?php esc_html_e( 'Exclude Categories (Multi-select)', 'dynamic-discount-rules-for-woocommerce' ); ?></label>
								<select id="wcdr_exclude_categories" name="exclude_categories[]" class="wcdr-select2 wc-enhanced-select" multiple="multiple" style="width: 100%;" data-placeholder="<?php esc_attr_e( 'Select categories to exclude...', 'dynamic-discount-rules-for-woocommerce' ); ?>">
									<?php foreach ( $all_categories as $cat_id => $cat_name ) : ?>
										<option value="<?php echo esc_attr( $cat_id ); ?>" <?php echo in_array( $cat_id, (array) $rule['exclude_categories'], true ) ? 'selected' : ''; ?>>
											<?php echo esc_html( $cat_name ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Products in these categories will NOT receive the discount.', 'dynamic-discount-rules-for-woocommerce' ); ?></p>
							</div>
							<div class="wcdr-form-group">
								<label for="wcdr_exclude_products" class="wcdr-label"><?php esc_html_e( 'Exclude Specific Products (Multi-select)', 'dynamic-discount-rules-for-woocommerce' ); ?></label>
								<select id="wcdr_exclude_products" name="exclude_products[]" class="wcdr-product-search wc-enhanced-select" multiple="multiple" style="width: 100%;" data-placeholder="<?php esc_attr_e( 'Search products to exclude by title or SKU...', 'dynamic-discount-rules-for-woocommerce' ); ?>">
									<?php
									if ( ! empty( $rule['exclude_products'] ) ) {
										foreach ( (array) $rule['exclude_products'] as $pid ) {
											$p = wc_get_product( $pid );
											if ( $p ) {
												echo '<option value="' . esc_attr( $pid ) . '" selected>#' . esc_html( $pid ) . ' ' . esc_html( $p->get_name() ) . '</option>';
											}
										}
									}
									?>
								</select>
								<p class="description"><?php esc_html_e( 'These specific products will be excluded from the discount.', 'dynamic-discount-rules-for-woocommerce' ); ?></p>
							</div>
						</div>
					</div>

					<div class="wcdr-section">
						<div class="wcdr-section-info">
							<h4><?php esc_html_e( 'Product types', 'dynamic-discount-rules-for-woocommerce' ); ?></h4>
							<p><?php esc_html_e( 'Only these types of product are discounted.', 'dynamic-discount-rules-for-woocommerce' ); ?></p>
						</div>
						<div class="wcdr-section-fields">
							<div class="wcdr-form-group">
								<label class="wcdr-label"><?php esc_html_e( 'Product Types (Simple, Variable, etc.)', 'dynamic-discount-rules-for-woocommerce' ); ?></label>
								<div class="wcdr-checkbox-group">
									<?php
									$selected_types = ! empty( $rule['product_types'] ) ? (array) $rule['product_types'] : [ 'simple', 'variable' ];
									foreach ( $product_types as $type_key => $type_label ) :
										$checked = in_array( $type_key, $selected_types, true );
									?>
										<label class="wcdr-checkbox-label">
											<input type="checkbox" name="product_types[]" value="<?php echo esc_attr( $type_key ); ?>" <?php checked( $checked, true ); ?>>
											<?php echo esc_html( $type_label ); ?>
										</label>
									<?php endforeach; ?>
								</div>
								<p class="description"><?php esc_html_e( 'Only products matching the selected types will be eligible.', 'dynamic-discount-rules-for-woocommerce' ); ?></p>
							</div>
						</div>
					</div>

				</section>

				<section class="wcdr-panel" id="wcdr-panel-conditions" data-title="<?php echo esc_attr__( 'Conditions', 'dynamic-discount-rules-for-woocommerce' ); ?>" data-icon="filter">

					<div class="wcdr-section">
						<div class="wcdr-section-info">
							<h4><?php esc_html_e( 'Cart total', 'dynamic-discount-rules-for-woocommerce' ); ?></h4>
							<p><?php esc_html_e( 'Use the rule only for carts in this range.', 'dynamic-discount-rules-for-woocommerce' ); ?></p>
						</div>
						<div class="wcdr-section-fields">
<div class="wcdr-grid-2">
								<div class="wcdr-form-group">
									<label for="wcdr_min_cart_total" class="wcdr-label"><?php esc_html_e( 'Minimum Cart Subtotal', 'dynamic-discount-rules-for-woocommerce' ); ?></label>
									<div class="wcdr-input-group">
										<span class="wcdr-input-addon"><?php echo esc_html( function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$' ); ?></span>
										<input type="number" step="0.01" min="0" id="wcdr_min_cart_total" name="min_cart_total" class="wcdr-input" value="<?php echo ! empty( $rule['min_cart_total'] ) ? esc_attr( $rule['min_cart_total'] ) : ''; ?>" placeholder="0.00">
									</div>
									<p class="description"><?php esc_html_e( 'Cart subtotal must be at least this amount. Leave blank for no minimum.', 'dynamic-discount-rules-for-woocommerce' ); ?></p>
								</div>
								<div class="wcdr-form-group">
									<label for="wcdr_max_cart_total" class="wcdr-label"><?php esc_html_e( 'Maximum Cart Subtotal', 'dynamic-discount-rules-for-woocommerce' ); ?></label>
									<div class="wcdr-input-group">
										<span class="wcdr-input-addon"><?php echo esc_html( function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$' ); ?></span>
										<input type="number" step="0.01" min="0" id="wcdr_max_cart_total" name="max_cart_total" class="wcdr-input" value="<?php echo ! empty( $rule['max_cart_total'] ) ? esc_attr( $rule['max_cart_total'] ) : ''; ?>" placeholder="0.00">
									</div>
									<p class="description"><?php esc_html_e( 'Cart subtotal must not exceed this amount. Leave blank for no maximum.', 'dynamic-discount-rules-for-woocommerce' ); ?></p>
								</div>
</div>
						</div>
					</div>

					<div class="wcdr-section">
						<div class="wcdr-section-info">
							<h4><?php esc_html_e( 'Customers', 'dynamic-discount-rules-for-woocommerce' ); ?></h4>
							<p><?php esc_html_e( 'Leave a box empty to allow everyone.', 'dynamic-discount-rules-for-woocommerce' ); ?></p>
						</div>
						<div class="wcdr-section-fields">
							<div class="wcdr-form-group">
								<label for="wcdr_user_roles" class="wcdr-label"><?php esc_html_e( 'User Roles (Multi-select)', 'dynamic-discount-rules-for-woocommerce' ); ?></label>
								<select id="wcdr_user_roles" name="user_roles[]" class="wcdr-select2 wc-enhanced-select" multiple="multiple" style="width: 100%;" data-placeholder="<?php esc_attr_e( 'Select user roles (leave empty for all)...', 'dynamic-discount-rules-for-woocommerce' ); ?>">
									<?php
									$selected_roles = ! empty( $rule['user_roles'] ) ? (array) $rule['user_roles'] : [];
									foreach ( $all_roles as $role_key => $role_name ) :
									?>
										<option value="<?php echo esc_attr( $role_key ); ?>" <?php echo in_array( $role_key, $selected_roles, true ) ? 'selected' : ''; ?>>
											<?php echo esc_html( $role_name ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Leave empty to allow ALL user roles and guests.', 'dynamic-discount-rules-for-woocommerce' ); ?></p>
							</div>
							<div class="wcdr-form-group">
								<label for="wcdr_payment_methods" class="wcdr-label"><?php esc_html_e( 'Payment Methods Condition (Multi-select)', 'dynamic-discount-rules-for-woocommerce' ); ?></label>
								<select id="wcdr_payment_methods" name="payment_methods[]" class="wcdr-select2 wc-enhanced-select" multiple="multiple" style="width: 100%;" data-placeholder="<?php esc_attr_e( 'Select payment methods (leave empty for all)...', 'dynamic-discount-rules-for-woocommerce' ); ?>">
									<?php
									$selected_gateways = ! empty( $rule['payment_methods'] ) ? (array) $rule['payment_methods'] : [];
									foreach ( $all_gateways as $gateway_id => $gateway_name ) :
									?>
										<option value="<?php echo esc_attr( $gateway_id ); ?>" <?php echo in_array( $gateway_id, $selected_gateways, true ) ? 'selected' : ''; ?>>
											<?php echo esc_html( $gateway_name ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Leave empty if this base discount applies for ALL payment methods (e.g. 10% base discount).', 'dynamic-discount-rules-for-woocommerce' ); ?></p>
							</div>
						</div>
					</div>

				</section>

				<section class="wcdr-panel" id="wcdr-panel-payment" data-title="<?php echo esc_attr__( 'Payment bonus', 'dynamic-discount-rules-for-woocommerce' ); ?>" data-icon="money-alt">

					<div class="wcdr-section">
						<div class="wcdr-section-info">
							<h4><?php esc_html_e( 'Online payment bonus', 'dynamic-discount-rules-for-woocommerce' ); ?></h4>
							<p><?php esc_html_e( 'An extra discount when the customer pays online. Cash on delivery does not get it.', 'dynamic-discount-rules-for-woocommerce' ); ?></p>
						</div>
						<div class="wcdr-section-fields">
							<div class="wcdr-payment-bonus-box wcdr-option-box">
								<div class="wcdr-form-group">
									<label class="wcdr-checkbox-label">
										<input type="checkbox" id="wcdr_enable_payment_bonus" name="enable_payment_bonus" value="yes" <?php checked( $rule['enable_payment_bonus'] ?? 'no', 'yes' ); ?>>
										<?php esc_html_e( 'Enable Additional Payment Method Discount (e.g. +5% extra for Online Payments, COD gets 0% extra)', 'dynamic-discount-rules-for-woocommerce' ); ?>
									</label>
									<p class="description">
										<?php esc_html_e( 'Example: 10% discount immediately on catalog, plus additional 5% for online payments (total 15%). When COD is selected, customer only receives the 10% discount.', 'dynamic-discount-rules-for-woocommerce' ); ?>
									</p>
								</div>

								<div id="wcdr_payment_bonus_fields" class="wcdr-bonus-fields" <?php echo ( ( $rule['enable_payment_bonus'] ?? 'no' ) === 'yes' ) ? '' : 'style="display: none;"'; ?>>
									<div class="wcdr-grid-2">
										<div class="wcdr-form-group">
											<label for="wcdr_payment_bonus_type" class="wcdr-label"><?php esc_html_e( 'Additional Discount Type', 'dynamic-discount-rules-for-woocommerce' ); ?></label>
											<select id="wcdr_payment_bonus_type" name="payment_bonus_type" class="wcdr-select">
												<option value="percentage" <?php selected( $rule['payment_bonus_type'] ?? 'percentage', 'percentage' ); ?>>
													<?php esc_html_e( 'Percentage Discount (%)', 'dynamic-discount-rules-for-woocommerce' ); ?>
												</option>
												<option value="fixed" <?php selected( $rule['payment_bonus_type'] ?? 'percentage', 'fixed' ); ?>>
													<?php /* translators: %s: currency symbol */ echo esc_html( sprintf( __( 'Fixed Amount (%s)', 'dynamic-discount-rules-for-woocommerce' ), function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$' ) ); ?>
												</option>
											</select>
										</div>

										<div class="wcdr-form-group">
											<label for="wcdr_payment_bonus_value" class="wcdr-label"><?php esc_html_e( 'Additional Discount Value (e.g. 5%)', 'dynamic-discount-rules-for-woocommerce' ); ?></label>
											<input type="number" step="0.01" min="0" id="wcdr_payment_bonus_value" name="payment_bonus_value" class="wcdr-input" value="<?php echo esc_attr( $rule['payment_bonus_value'] ?? 5 ); ?>">
										</div>
									</div>

									<div class="wcdr-form-group">
										<label for="wcdr_payment_bonus_gateways" class="wcdr-label"><?php esc_html_e( 'Payment Gateways Eligible for Additional Discount (Multi-select)', 'dynamic-discount-rules-for-woocommerce' ); ?></label>
										<select id="wcdr_payment_bonus_gateways" name="payment_bonus_gateways[]" class="wcdr-select2 wc-enhanced-select" multiple="multiple" style="width: 100%;" data-placeholder="<?php esc_attr_e( 'Select online payment methods (exclude COD)...', 'dynamic-discount-rules-for-woocommerce' ); ?>">
											<?php
											$bonus_gateways = ! empty( $rule['payment_bonus_gateways'] ) ? (array) $rule['payment_bonus_gateways'] : [];
											foreach ( $all_gateways as $gateway_id => $gateway_name ) :
											?>
												<option value="<?php echo esc_attr( $gateway_id ); ?>" <?php echo in_array( $gateway_id, $bonus_gateways, true ) ? 'selected' : ''; ?>>
													<?php echo esc_html( $gateway_name ); ?>
												</option>
											<?php endforeach; ?>
										</select>
										<p class="description"><?php esc_html_e( 'Select the online payment methods eligible for this extra discount (e.g. Stripe, Razorpay, PhonePe, Bank Transfer). Leave COD unselected so COD does not receive this extra 5%.', 'dynamic-discount-rules-for-woocommerce' ); ?></p>
									</div>

									<div class="wcdr-form-group" style="margin-bottom: 0;">
										<label for="wcdr_payment_bonus_label" class="wcdr-label"><?php esc_html_e( 'Additional Discount Line Label', 'dynamic-discount-rules-for-woocommerce' ); ?></label>
										<input type="text" id="wcdr_payment_bonus_label" name="payment_bonus_label" class="regular-text wcdr-input-full" value="<?php echo esc_attr( ! empty( $rule['payment_bonus_label'] ) ? $rule['payment_bonus_label'] : __( 'Online Payment Additional Discount', 'dynamic-discount-rules-for-woocommerce' ) ); ?>" placeholder="<?php esc_attr_e( 'e.g. Online Payment Additional Discount', 'dynamic-discount-rules-for-woocommerce' ); ?>">
										<p class="description"><?php esc_html_e( 'This label will be clearly displayed in the checkout order totals breakdown.', 'dynamic-discount-rules-for-woocommerce' ); ?></p>
									</div>
								</div>
							</div>
						</div>
					</div>

				</section>

					<?php
					/**
					 * Lets add-ons add their own panels to the rule form. A panel is a
					 * <section class="wcdr-panel" data-title="Name" data-icon="dashicon" data-pro="1">.
					 *
					 * @param array $rule Rule being edited.
					 */
					do_action( 'wcdr_rule_form_cards', $rule );
					?>
				</div>
			</div>
			</div>

			<aside class="wcdr-form-sidebar">
				<div class="wcdr-side-card">
					<h4><?php esc_html_e( 'Publish', 'dynamic-discount-rules-for-woocommerce' ); ?></h4>
					<label class="wcdr-switch-row wcdr-rule-status">
						<input type="hidden" name="status" value="inactive">
						<input type="checkbox" name="status" value="active" <?php checked( $rule['status'] ?? 'active', 'active' ); ?>>
						<?php esc_html_e( 'Rule is active', 'dynamic-discount-rules-for-woocommerce' ); ?>
					</label>
					<div class="wcdr-form-group">
						<label for="wcdr_priority" class="wcdr-label"><?php esc_html_e( 'Priority', 'dynamic-discount-rules-for-woocommerce' ); ?></label>
						<input type="number" min="1" id="wcdr_priority" name="priority" class="wcdr-input" value="<?php echo esc_attr( $rule['priority'] ?? 10 ); ?>">
						<p class="description"><?php esc_html_e( 'Lower numbers are checked first.', 'dynamic-discount-rules-for-woocommerce' ); ?></p>
					</div>
					<div class="wcdr-side-actions">
						<button type="submit" class="wcdr-btn wcdr-btn-primary"><?php echo $is_edit ? esc_html__( 'Update rule', 'dynamic-discount-rules-for-woocommerce' ) : esc_html__( 'Save rule', 'dynamic-discount-rules-for-woocommerce' ); ?></button>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wcdr-discount-rules' ) ); ?>" class="wcdr-btn wcdr-btn-ghost"><?php esc_html_e( 'Cancel', 'dynamic-discount-rules-for-woocommerce' ); ?></a>
					</div>
				</div>

				<div class="wcdr-side-card wcdr-summary"
					data-example="<?php esc_attr_e( 'A product that costs {old} will cost {new}.', 'dynamic-discount-rules-for-woocommerce' ); ?>"
					data-empty="<?php esc_attr_e( 'Enter a discount value to see an example.', 'dynamic-discount-rules-for-woocommerce' ); ?>"
					data-off="<?php esc_attr_e( 'OFF', 'dynamic-discount-rules-for-woocommerce' ); ?>">
					<h4><?php esc_html_e( 'Preview', 'dynamic-discount-rules-for-woocommerce' ); ?></h4>
					<div class="wcdr-summary-badge" id="wcdr-sum-badge">&ndash;</div>
					<p id="wcdr-sum-text"></p>
				</div>

				<?php $this->render_side_info(); ?>
			</aside>
			</div>
		</form>
		<?php
	}

	/**
	 * Sidebar cards shared by the rule form and the settings screen: tips and, without Pro, the upgrade card.
	 */
	private function render_side_info() {
		?>
		<div class="wcdr-side-card">
			<h4><?php esc_html_e( 'How rules work', 'dynamic-discount-rules-for-woocommerce' ); ?></h4>
			<ul class="wcdr-tips">
				<li><strong><?php esc_html_e( 'Exclusions', 'dynamic-discount-rules-for-woocommerce' ); ?></strong> <?php esc_html_e( 'always win over the products you picked.', 'dynamic-discount-rules-for-woocommerce' ); ?></li>
				<li><strong><?php esc_html_e( 'Payment method', 'dynamic-discount-rules-for-woocommerce' ); ?></strong> <?php esc_html_e( 'updates live when the customer changes gateway at checkout.', 'dynamic-discount-rules-for-woocommerce' ); ?></li>
				<li><strong><?php esc_html_e( 'Cart total', 'dynamic-discount-rules-for-woocommerce' ); ?></strong> <?php esc_html_e( 'is checked against the subtotal before discounts.', 'dynamic-discount-rules-for-woocommerce' ); ?></li>
				<li><strong><?php esc_html_e( 'Stacking', 'dynamic-discount-rules-for-woocommerce' ); ?></strong> <?php esc_html_e( 'can be switched to "highest discount only" in Settings.', 'dynamic-discount-rules-for-woocommerce' ); ?></li>
			</ul>
		</div>

		<?php if ( ! has_action( 'wcdr_rule_form_cards' ) ) : ?>
			<div class="wcdr-side-card wcdr-upsell">
				<h4><?php esc_html_e( 'Go Pro', 'dynamic-discount-rules-for-woocommerce' ); ?> <span class="wcdr-pro-pill">PRO</span></h4>
				<ul class="wcdr-tips">
					<li><?php esc_html_e( 'Unlimited rules', 'dynamic-discount-rules-for-woocommerce' ); ?></li>
					<li><?php esc_html_e( 'Quantity and tiered discounts with a bulk pricing table', 'dynamic-discount-rules-for-woocommerce' ); ?></li>
					<li><?php esc_html_e( 'Schedule rules by date, day and hour', 'dynamic-discount-rules-for-woocommerce' ); ?></li>
					<li><?php esc_html_e( 'First order discount', 'dynamic-discount-rules-for-woocommerce' ); ?></li>
				</ul>
				<a href="<?php echo esc_url( WCDR_Rules_Manager::upgrade_url() ); ?>" target="_blank" rel="noopener noreferrer" class="wcdr-btn wcdr-btn-primary"><?php esc_html_e( 'Upgrade to Pro', 'dynamic-discount-rules-for-woocommerce' ); ?></a>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render Global Settings Tab.
	 */
	private function render_settings_tab() {
		$settings = WCDR_Rules_Manager::get_default_settings();
		?>
		<div class="wcdr-form-layout">
		<div class="wcdr-form-main">
		<div class="wcdr-card wcdr-card-narrow">
			<div class="wcdr-card-header">
				<span class="dashicons dashicons-admin-settings"></span>
				<h3><?php esc_html_e( 'Global Discount Settings', 'dynamic-discount-rules-for-woocommerce' ); ?></h3>
			</div>
			<div class="wcdr-card-body">
				<form method="post" action="">
					<?php wp_nonce_field( 'wcdr_save_settings_nonce', 'wcdr_nonce' ); ?>
					<input type="hidden" name="wcdr_action" value="save_settings">

					<!-- Enable Plugin -->
					<div class="wcdr-form-group">
						<label class="wcdr-label"><?php esc_html_e( 'Enable Discount Rules System', 'dynamic-discount-rules-for-woocommerce' ); ?></label>
						<label class="wcdr-checkbox-label">
							<input type="checkbox" name="enabled" value="yes" <?php checked( $settings['enabled'], 'yes' ); ?>>
							<?php esc_html_e( 'Enable all active discount rules on store checkout and cart', 'dynamic-discount-rules-for-woocommerce' ); ?>
						</label>
					</div>

					<!-- Show Catalog Strikethrough Price -->
					<div class="wcdr-form-group">
						<label class="wcdr-label"><?php esc_html_e( 'Display Discount on Shop & Product Pages', 'dynamic-discount-rules-for-woocommerce' ); ?></label>
						<label class="wcdr-checkbox-label">
							<input type="checkbox" name="show_catalog_strikethrough" value="yes" <?php checked( $settings['show_catalog_strikethrough'] ?? 'yes', 'yes' ); ?>>
							<?php esc_html_e( 'Show the regular price struck out next to the discounted price on Shop, Archive and Product pages', 'dynamic-discount-rules-for-woocommerce' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Eligible products show their real regular price crossed out and the lower price the customer pays.', 'dynamic-discount-rules-for-woocommerce' ); ?></p>
					</div>

					<!-- Rule Combination Mode -->
					<div class="wcdr-form-group">
						<label for="wcdr_rule_combination" class="wcdr-label"><?php esc_html_e( 'Rule Combination Mode', 'dynamic-discount-rules-for-woocommerce' ); ?></label>
						<select id="wcdr_rule_combination" name="rule_combination" class="wcdr-select">
							<option value="stack" <?php selected( $settings['rule_combination'], 'stack' ); ?>>
								<?php esc_html_e( 'Stack all rules (discounts add up)', 'dynamic-discount-rules-for-woocommerce' ); ?>
							</option>
							<option value="highest" <?php selected( $settings['rule_combination'], 'highest' ); ?>>
								<?php esc_html_e( 'Highest discount only', 'dynamic-discount-rules-for-woocommerce' ); ?>
							</option>
						</select>
						<p class="description"><?php esc_html_e( 'Choose whether multiple active discount rules stack together or only the largest discount applies.', 'dynamic-discount-rules-for-woocommerce' ); ?></p>
					</div>

					<!-- Tax Handling -->
					<div class="wcdr-form-group">
						<label for="wcdr_tax_handling" class="wcdr-label"><?php esc_html_e( 'Discount Tax Handling', 'dynamic-discount-rules-for-woocommerce' ); ?></label>
						<select id="wcdr_tax_handling" name="tax_handling" class="wcdr-select">
							<option value="taxable" <?php selected( $settings['tax_handling'], 'taxable' ); ?>>
								<?php esc_html_e( 'Taxable (reduces the tax too)', 'dynamic-discount-rules-for-woocommerce' ); ?>
							</option>
							<option value="non_taxable" <?php selected( $settings['tax_handling'], 'non_taxable' ); ?>>
								<?php esc_html_e( 'Non-taxable (tax stays the same)', 'dynamic-discount-rules-for-woocommerce' ); ?>
							</option>
						</select>
					</div>

					<!-- Cart Notice -->
					<div class="wcdr-form-group">
						<label class="wcdr-label"><?php esc_html_e( 'Cart & Checkout Discount Notice', 'dynamic-discount-rules-for-woocommerce' ); ?></label>
						<label class="wcdr-checkbox-label">
							<input type="checkbox" name="show_cart_notice" value="yes" <?php checked( $settings['show_cart_notice'], 'yes' ); ?>>
							<?php esc_html_e( 'Show green savings banner on Cart and Checkout pages when discount is applied', 'dynamic-discount-rules-for-woocommerce' ); ?>
						</label>
					</div>

					<!-- Cart Notice Text -->
					<div class="wcdr-form-group">
						<label for="wcdr_cart_notice_text" class="wcdr-label"><?php esc_html_e( 'Notice Message Template', 'dynamic-discount-rules-for-woocommerce' ); ?></label>
						<input type="text" id="wcdr_cart_notice_text" name="cart_notice_text" class="regular-text wcdr-input-full" value="<?php echo esc_attr( $settings['cart_notice_text'] ); ?>">
						<p class="description"><?php esc_html_e( 'Use {discount_amount} as placeholder for formatted savings (for example 15.00).', 'dynamic-discount-rules-for-woocommerce' ); ?></p>
					</div>

					<div style="margin-top: 25px;">
						<button type="submit" class="button button-primary button-large">
							<?php esc_html_e( 'Save Settings', 'dynamic-discount-rules-for-woocommerce' ); ?>
						</button>
					</div>
				</form>
			</div>
		</div>
		</div>
		<aside class="wcdr-form-sidebar">
			<?php $this->render_side_info(); ?>
		</aside>
		</div>
		<?php
	}

	/**
	 * Render Help & Recipe Examples Tab.
	 */
	private function render_help_tab() {
		?>
		<div class="wcdr-recipes-grid">
			<!-- Recipe 1 -->
			<div class="wcdr-card">
				<div class="wcdr-card-header">
					<span class="dashicons dashicons-cart"></span>
					<h3><?php esc_html_e( 'Recipe 1: Category Discount with Exclusions', 'dynamic-discount-rules-for-woocommerce' ); ?></h3>
				</div>
				<div class="wcdr-card-body">
					<p><?php esc_html_e( 'Apply a 15% discount on the entire "Clothing" category, while excluding "Clearance" items.', 'dynamic-discount-rules-for-woocommerce' ); ?></p>
					<ul>
						<li><strong><?php esc_html_e( 'Target Scope:', 'dynamic-discount-rules-for-woocommerce' ); ?></strong> <?php esc_html_e( 'Specific Categories Only', 'dynamic-discount-rules-for-woocommerce' ); ?></li>
						<li><strong><?php esc_html_e( 'Include Categories:', 'dynamic-discount-rules-for-woocommerce' ); ?></strong> <?php esc_html_e( 'Clothing', 'dynamic-discount-rules-for-woocommerce' ); ?></li>
						<li><strong><?php esc_html_e( 'Exclude Categories:', 'dynamic-discount-rules-for-woocommerce' ); ?></strong> <?php esc_html_e( 'Clearance', 'dynamic-discount-rules-for-woocommerce' ); ?></li>
						<li><strong><?php esc_html_e( 'Discount:', 'dynamic-discount-rules-for-woocommerce' ); ?></strong> 15%</li>
					</ul>
				</div>
			</div>

			<!-- Recipe 2 -->
			<div class="wcdr-card">
				<div class="wcdr-card-header">
					<span class="dashicons dashicons-money-alt"></span>
					<h3><?php esc_html_e( 'Recipe 2: Payment Gateway Incentive', 'dynamic-discount-rules-for-woocommerce' ); ?></h3>
				</div>
				<div class="wcdr-card-body">
					<p><?php esc_html_e( 'Encourage Direct Bank Transfer (BACS) or Cash on Delivery with a 5% discount.', 'dynamic-discount-rules-for-woocommerce' ); ?></p>
					<ul>
						<li><strong><?php esc_html_e( 'Target Scope:', 'dynamic-discount-rules-for-woocommerce' ); ?></strong> <?php esc_html_e( 'All Products', 'dynamic-discount-rules-for-woocommerce' ); ?></li>
						<li><strong><?php esc_html_e( 'Payment Methods:', 'dynamic-discount-rules-for-woocommerce' ); ?></strong> Direct Bank Transfer [bacs]</li>
						<li><strong><?php esc_html_e( 'Discount:', 'dynamic-discount-rules-for-woocommerce' ); ?></strong> 5%</li>
					</ul>
				</div>
			</div>

			<!-- Recipe 3 -->
			<div class="wcdr-card">
				<div class="wcdr-card-header">
					<span class="dashicons dashicons-chart-pie"></span>
					<h3><?php esc_html_e( 'Recipe 3: Cart Total Min / Max Threshold', 'dynamic-discount-rules-for-woocommerce' ); ?></h3>
				</div>
				<div class="wcdr-card-body">
					<p><?php esc_html_e( 'Give 10% discount when customers spend between $100 and $500.', 'dynamic-discount-rules-for-woocommerce' ); ?></p>
					<ul>
						<li><strong><?php esc_html_e( 'Min Cart Subtotal:', 'dynamic-discount-rules-for-woocommerce' ); ?></strong> 100</li>
						<li><strong><?php esc_html_e( 'Max Cart Subtotal:', 'dynamic-discount-rules-for-woocommerce' ); ?></strong> 500</li>
						<li><strong><?php esc_html_e( 'Discount:', 'dynamic-discount-rules-for-woocommerce' ); ?></strong> 10%</li>
					</ul>
				</div>
			</div>

			<!-- Recipe 4 -->
			<div class="wcdr-card">
				<div class="wcdr-card-header">
					<span class="dashicons dashicons-groups"></span>
					<h3><?php esc_html_e( 'Recipe 4: VIP / User Role Discount', 'dynamic-discount-rules-for-woocommerce' ); ?></h3>
				</div>
				<div class="wcdr-card-body">
					<p><?php esc_html_e( 'Offer wholesale or VIP logged-in customers a dedicated 20% discount storewide.', 'dynamic-discount-rules-for-woocommerce' ); ?></p>
					<ul>
						<li><strong><?php esc_html_e( 'User Roles:', 'dynamic-discount-rules-for-woocommerce' ); ?></strong> Wholesale Customer / Customer</li>
						<li><strong><?php esc_html_e( 'Discount:', 'dynamic-discount-rules-for-woocommerce' ); ?></strong> 20%</li>
					</ul>
				</div>
			</div>
		</div>
		<?php
	}
}
