/**
 * WooCommerce Dynamic Discount Rules - Admin JS
 */
(function ($) {
	'use strict';

	$(function () {
		// 0. Material style fields: a label that sits right above one input or select floats on its border.
		$('.wcdr-admin-wrapper .wcdr-form-group').each(function () {
			var $group = $(this);
			var $label = $group.children('label.wcdr-label').first();
			if (!$label.length || $group.children().first()[0] !== $label[0]) {
				return;
			}
			var $field = $label.next();
			if ($field.is('input:not([type=checkbox]):not([type=radio]), select, .wcdr-input-group')) {
				$group.addClass('wcdr-mui');
			}
		});

		// The label floats up when the field has a value or focus (Material filled text field).
		function wcdrSyncLabel($group) {
			var $field = $group.find('input:not(.select2-search__field), select').first();
			var filled = false;
			if ($group.find('.wcdr-input-group').length || $field.is('[type=date], [type=datetime-local], [type=time]')) {
				filled = true;
			} else if ($field.is('select')) {
				var val = $field.val();
				filled = $field.prop('multiple') ? !!(val && val.length) : true;
			} else {
				filled = $.trim($field.val()) !== '';
			}
			$group.toggleClass('is-filled', filled);
		}
		var $muiGroups = $('.wcdr-admin-wrapper .wcdr-mui');
		$muiGroups.each(function () { wcdrSyncLabel($(this)); });
		$(document).on('input change select2:select select2:unselect select2:clear', '.wcdr-admin-wrapper .wcdr-mui', function () {
			wcdrSyncLabel($(this));
		});
		$(document).on('focusin', '.wcdr-admin-wrapper .wcdr-mui', function () {
			$(this).addClass('is-focused');
		}).on('focusout', '.wcdr-admin-wrapper .wcdr-mui', function () {
			var $group = $(this);
			window.setTimeout(function () {
				if (!$group.find(':focus').length && !$('.select2-container--open').length) {
					$group.removeClass('is-focused');
				}
				wcdrSyncLabel($group);
			}, 0);
		});

		// 1. Initialize Standard Select2 / selectWoo
		if ($.fn.selectWoo || $.fn.select2) {
			var selectFn = $.fn.selectWoo ? 'selectWoo' : 'select2';

			$('.wcdr-select2').each(function () {
				var $el = $(this);
				var placeholder = $el.data('placeholder') || 'Click to select...';
				$el[selectFn]({
					width: '100%',
					placeholder: placeholder,
					allowClear: true
				});
			});

			// 2. Initialize AJAX Product Search Select2
			$('.wcdr-product-search').each(function () {
				var $el = $(this);
				var placeholder = $el.data('placeholder') || 'Search products by title or SKU...';
				$el[selectFn]({
					width: '100%',
					placeholder: placeholder,
					allowClear: true,
					minimumInputLength: 2,
					ajax: {
						url: wcdr_admin_params.ajax_url,
						dataType: 'json',
						delay: 300,
						data: function (params) {
							return {
								action: 'wcdr_search_products',
								security: wcdr_admin_params.nonce,
								term: params.term
							};
						},
						processResults: function (response) {
							if (response && response.success && response.data) {
								return {
									results: response.data
								};
							}
							return { results: [] };
						},
						cache: true
					}
				});
			});
		}

		// The open dropdown lives outside the page wrapper: tag it so the plugin styles reach it.
		$(document).on('select2:open', function () {
			window.setTimeout(function () {
				$('.select2-container--open .select2-dropdown').addClass('wcdr-dropdown');
			}, 0);
		});

		$muiGroups.each(function () { wcdrSyncLabel($(this)); });

		// 3. Dynamic Discount Type Addon Symbol
		$('#wcdr_discount_type').on('change', function () {
			var type = $(this).val();
			var addon = $('#wcdr_discount_addon');
			if (type === 'percentage') {
				addon.text('%');
				$('#wcdr_discount_value').attr('max', '100');
			} else {
				addon.text(wcdr_admin_params.currency_symbol || '$');
				$('#wcdr_discount_value').removeAttr('max');
			}
		}).trigger('change');

		// 3b. Live preview in the sidebar of the rule form.
		var $summary = $('.wcdr-summary');
		if ($summary.length) {
			var symbol = wcdr_admin_params.currency_symbol || '';
			var fmt = function (n) {
				return symbol + (Math.round(n * 100) / 100).toFixed(2);
			};
			var updateSummary = function () {
				var type = $('#wcdr_discount_type').val();
				var value = parseFloat($('#wcdr_discount_value').val()) || 0;
				var $badge = $('#wcdr-sum-badge');
				var $text = $('#wcdr-sum-text');
				if (value <= 0) {
					$badge.text('\u2013');
					$text.text($summary.data('empty'));
					return;
				}
				var base = 100;
				var cut = type === 'percentage' ? base * Math.min(value, 100) / 100 : Math.min(value, base);
				var max = parseFloat($('#wcdr_max_discount').val()) || 0;
				if (max > 0) {
					cut = Math.min(cut, max);
				}
				$badge.text((type === 'percentage' ? value + '%' : fmt(value)) + ' ' + $summary.data('off'));
				$text.text(String($summary.data('example')).replace('{old}', fmt(base)).replace('{new}', fmt(base - cut)));
			};
			$('#wcdr_discount_type, #wcdr_discount_value, #wcdr_max_discount').on('input change', updateSummary);
			updateSummary();
		}

		// 4. Target Scope Conditional Visibility
		function updateScopeVisibility() {
			var scope = $('#wcdr_apply_to').val();
			if (scope === 'all') {
				$('.wcdr-field-categories').slideUp(200);
				$('.wcdr-field-products').slideUp(200);
			} else if (scope === 'categories') {
				$('.wcdr-field-categories').slideDown(200);
				$('.wcdr-field-products').slideUp(200);
			} else if (scope === 'products') {
				$('.wcdr-field-categories').slideUp(200);
				$('.wcdr-field-products').slideDown(200);
			} else if (scope === 'both') {
				$('.wcdr-field-categories').slideDown(200);
				$('.wcdr-field-products').slideDown(200);
			}
		}
		$('#wcdr_apply_to').on('change', updateScopeVisibility);
		updateScopeVisibility();

		// 4b. Payment Bonus Fields Toggle
		$('#wcdr_enable_payment_bonus').on('change', function () {
			if ($(this).is(':checked')) {
				$('#wcdr_payment_bonus_fields').slideDown(200);
			} else {
				$('#wcdr_payment_bonus_fields').slideUp(200);
			}
		});

		// 4c. Rule form: one tab per panel (add-ons can add panels, marked with data-pro).
		var $subtabs = $('.wcdr-subtabs');
		if ($subtabs.length) {
			var $panels = $('.wcdr-panel');

			var showPanel = function (id) {
				$panels.removeClass('is-active').filter('#' + id).addClass('is-active');
				$subtabs.find('.wcdr-subtab').removeClass('is-active').attr('aria-selected', 'false')
					.filter('[data-panel="' + id + '"]').addClass('is-active').attr('aria-selected', 'true');
			};

			$panels.each(function () {
				var $panel = $(this);
				var $tab = $('<button type="button" class="wcdr-subtab" role="tab"></button>')
					.attr('data-panel', this.id)
					.append($('<span class="dashicons"></span>').addClass('dashicons-' + ($panel.data('icon') || 'admin-generic')))
					.append(document.createTextNode($panel.data('title') || ''));
				if ($panel.data('pro')) {
					$tab.append('<span class="wcdr-pro-pill">PRO</span>');
				}
				$subtabs.append($tab);
			});
			$('.wcdr-panels').addClass('is-tabbed');

			$subtabs.on('click', '.wcdr-subtab', function () {
				showPanel($(this).attr('data-panel'));
			});

			showPanel($panels.first().attr('id'));

			// A field the browser cannot reach in a hidden tab: open that tab.
			$('#wcdr-rule-edit-form').find('input, select, textarea').on('invalid', function () {
				var id = $(this).closest('.wcdr-panel').attr('id');
				if (id) {
					showPanel(id);
				}
			});
		}

		// 5. AJAX Status Toggle on Rules List Table
		$('.wcdr-toggle-status').on('change', function () {
			var checkbox = $(this);
			var ruleId = checkbox.data('rule-id');
			var row = $('#rule-row-' + ruleId);

			checkbox.prop('disabled', true);

			$.ajax({
				url: wcdr_admin_params.ajax_url,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'wcdr_toggle_rule',
					security: wcdr_admin_params.nonce,
					rule_id: ruleId
				},
				success: function (res) {
					checkbox.prop('disabled', false);
					if (res && res.success) {
						var isActive = res.data.status === 'active';
						var pill = row.find('.wcdr-status-pill');
						row.toggleClass('is-inactive', !isActive);
						pill.toggleClass('is-active', isActive).toggleClass('is-inactive', !isActive)
							.text(isActive ? pill.data('active') : pill.data('inactive'));
					} else {
						alert((res && res.data && res.data.message) ? res.data.message : 'Error toggling rule status');
						checkbox.prop('checked', !checkbox.prop('checked'));
					}
				},
				error: function () {
					checkbox.prop('disabled', false);
					checkbox.prop('checked', !checkbox.prop('checked'));
					alert('Network error while toggling rule status.');
				}
			});
		});

		// 6. AJAX Duplicate Rule
		$('.wcdr-duplicate-btn').on('click', function (e) {
			e.preventDefault();
			var btn = $(this);
			var ruleId = btn.data('rule-id');

			btn.prop('disabled', true).find('.dashicons').addClass('dashicons-update spin');

			$.ajax({
				url: wcdr_admin_params.ajax_url,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'wcdr_duplicate_rule',
					security: wcdr_admin_params.nonce,
					rule_id: ruleId
				},
				success: function (res) {
					if (res && res.success) {
						window.location.reload();
					} else {
						btn.prop('disabled', false).find('.dashicons').removeClass('dashicons-update spin');
						alert((res && res.data && res.data.message) ? res.data.message : 'Error duplicating rule');
					}
				},
				error: function () {
					btn.prop('disabled', false).find('.dashicons').removeClass('dashicons-update spin');
					alert('Network error duplicating rule.');
				}
			});
		});

	});
})(jQuery);
