/**
 * WooCommerce Dynamic Discount Rules - Frontend JS
 *
 * 1. Automatically fixes theme sale badges (e.g. Elessi / Nasa -0% / 0%) to show the correct discount % (-10%).
 * 2. Ensures real-time recalculation of checkout review fragments when customer switches payment methods.
 */
(function ($) {
	'use strict';

	function fixThemeSaleBadges() {
		// Scan for sale badges that erroneously render -0% or 0% due to theme calculation
		$('.badge.sale-label, .sale-label, .onsale, .nasa-badges-wrap .badge').each(function () {
			var $badge = $(this);
			var text = $badge.text().replace(/\s+/g, '').trim();

			// If badge text is -0% or 0%
			if (text === '-0%' || text === '0%') {
				var rate = 10; // Default to 10%

				// Try finding the nearest product card/container
				var $container = $badge.closest('.product, .product-item, .product-warp, .product-item-wrap, .large-6, .summary, li.product');
				var $wcdrBadge = $container.length ? $container.find('.wcdr-discount-badge').first() : $();

				// If not found in immediate container, look across single product summary if on single product page
				if (!$wcdrBadge.length) {
					$wcdrBadge = $('.summary .wcdr-discount-badge, .entry-summary .wcdr-discount-badge, .product-info .wcdr-discount-badge').first();
				}

				if ($wcdrBadge.length) {
					var match = $wcdrBadge.text().match(/(\d+)%/);
					if (match && match[1]) {
						rate = parseInt(match[1], 10);
					}
				}

				// Update badge to display formatted percentage
				$badge.html('&#045;' + rate + '&#037;');
			}
		});
	}

	$(document).ready(function () {
		// Initial badge fix
		fixThemeSaleBadges();

		// Fallback after images and dynamic layouts finish rendering
		$(window).on('load', fixThemeSaleBadges);

		// Re-run after any AJAX completion (infinite scroll, filtering, quick view, variation change)
		$(document).ajaxComplete(function () {
			setTimeout(fixThemeSaleBadges, 80);
		});

		// Listen for payment method change and trigger checkout fragment update
		$(document).on('change', 'input[name="payment_method"], select[name="payment_method"]', function () {
			$('body').trigger('update_checkout');
		});
	});
})(jQuery);
