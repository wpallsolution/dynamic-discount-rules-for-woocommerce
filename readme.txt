=== Dynamic Discount Rules for WooCommerce ===
Contributors: hplugin
Tags: woocommerce, discount, dynamic pricing, cart discount, role discount
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create discount rules by category, product, user role, payment method and cart total. Discounts change the real price.

== Description ==

**[Read the documentation](https://docs.wpallsolution.com/#/collection/ddr-plugin)** – installation, every setting explained and answers. Need help? **[Contact support](https://support.wpallsolution.com/)**.

> **Need more? Dynamic Discount Rules Pro** (a separate add-on that needs this free plugin) removes the 10-rule limit and adds quantity tiers with a bulk pricing table, scheduling and first order discounts. See "Free vs Pro" below.

**Dynamic Discount Rules for WooCommerce** gives store owners full control over discounts and promotional pricing in WooCommerce.

Key Features:
* **Category Discounts**: Apply percentage or fixed discounts to specific product categories (multiple categories supported).
* **Category Exclusions**: Exclude specific categories from discounts.
* **Product Discounts**: Target specific products (multiple product selection with AJAX search).
* **Product Exclusions**: Exclude specific products from receiving discounts.
* **Percentage & Fixed Discounts**: Easy percentage discount input with validation (0-100%) and an optional maximum discount per item.
* **Cart Total Min & Max**: Restrict discounts by minimum and maximum cart subtotal thresholds.
* **Product Type Restrictions**: Restrict discounts to Simple, Variable/Variation, Grouped, or External products.
* **Payment Method Discounts**: Automatically calculate discounts at checkout based on chosen payment method (Cash on Delivery, Bank Transfer, Stripe, etc.) in real-time.
* **User Role Discounts**: Offer tailored discounts for specific customer roles (e.g., Wholesale, VIP, Customer, or Guests).
* **Flexible Application**: Discounts shown on shop pages change the item price. Rules that are not shown on shop pages can be taken off as a discount line in the cart totals.
* **Rule Combination Modes**: Stacking discounts cumulatively or applying the highest discount.
* **WPML Compatible**: Rules made for the original product or category also apply to its translations, and rule names, the payment bonus label and the cart notice can be translated with WPML String Translation.
* **HPOS Compatible**: Declares compatibility with WooCommerce High-Performance Order Storage (custom_order_tables).
* **Cart and Checkout blocks**: Compatibility with the block-based Cart and Checkout is declared. Item price discounts show in the block cart, but the cart notice and discount lines use classic hooks, and the script that refreshes the discount when the payment method changes listens to classic checkout fields. Test payment method rules on your own checkout.
* **Documentation**: Every setting is explained in the [documentation](https://docs.wpallsolution.com/#/collection/ddr-plugin).

= Free vs Pro =

The free version gives you 10 rules with all the discount types above. The Pro add-on removes the limit and adds quantity tiers, scheduling and first order discounts.

<pre>
Feature                                   Free    Pro
----------------------------------------  ------  ------
Percentage and fixed discounts            Yes     Yes
Product, category and variation rules     Yes     Yes
Exclusions                                Yes     Yes
User role conditions                      Yes     Yes
Payment method (online payment bonus)     Yes     Yes
Cart minimum and maximum                  Yes     Yes
Shop price discount and cart notice       Yes     Yes
WPML support                              Yes     Yes
Number of rules                           10      Unlimited
Quantity (tiered) discounts, bulk table   -       Yes
Schedule (dates, days, time of day)       -       Yes
First order discount                      -       Yes
</pre>

Pro is a separate plugin. Questions about it? [Contact us](https://support.wpallsolution.com/).

== Installation ==

1. Upload the `dynamic-discount-rules-for-woocommerce` folder to the `/wp-content/plugins/` directory, or upload the ZIP file through WordPress Plugins > Add New > Upload Plugin.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to **WooCommerce > Discount Rules** to configure rules and global settings.

Need more detail? See the [documentation](https://docs.wpallsolution.com/#/collection/ddr-plugin).

== Frequently Asked Questions ==

= How can I get support? =
Please [contact us here](https://support.wpallsolution.com/) for help, a new feature or a customization.

= Where is the documentation? =
[Read the documentation](https://docs.wpallsolution.com/#/collection/ddr-plugin) for the installation steps, every setting explained and the rule examples.

= Why is there a limit of 10 rules? =
The free version supports 10 rules. Existing rules keep working and can be edited or deleted; you only cannot add an eleventh. The Pro add-on removes the limit.

= Does it work with WPML? =
Yes. Pick the original products and categories in your rules; their translations get the same discount. Translate the rule names, the payment bonus label and the cart notice under WPML > String Translation (context: dynamic-discount-rules-for-woocommerce).

= Does it convert fixed amounts for multi-currency stores? =
Not yet. Fixed discounts, maximum discounts and the minimum and maximum cart totals are used as entered, in the store currency. Percentage discounts work in every currency.

= Does it work with the block-based Cart and Checkout? =
Compatibility is declared, and item price changes show in the block cart. The cart notice and the discount lines use classic Cart and Checkout hooks, and the payment method refresh listens to classic checkout payment fields, so payment method rules may not update live in block checkout. Please test on your own site.

= Does this work with Variable products? =
Yes! Both parent variable products and individual variations are supported.

= How does payment method discount work? =
When a customer selects a qualifying payment gateway on the classic Checkout page, WooCommerce refreshes the checkout fragments and the discount is applied.

= Can I exclude sale or clearance items? =
Yes! You can exclude categories or specific products. Exclusions always take priority over inclusions.

== Uninstall ==

Deleting the plugin from the Plugins screen removes its stored rules, settings and rules cache.

== Changelog ==

= 1.3.0 =
* New: the free version supports up to 10 rules, with an upgrade notice when the limit is reached. Existing rules are never removed.
* Tweak: new admin design: one top bar, one card per rule in the rules list, and a rule form with tabs (Discount, Products, Conditions, Payment bonus) that also works on phones.
* Fix: fixed amount discounts showed raw HTML in the rules list, and the currency symbol was always "$" in some labels.
* Fix: a rule with no category or product chosen is now flagged in the rules list.
* Fix: editing a rule that was saved earlier could create a second copy instead of updating it.
* Dev: new filters and an action for add-ons: wcdr_default_rule, wcdr_sanitize_rule, wcdr_rule_applies, wcdr_rule_has_discount, wcdr_unit_discount and wcdr_rule_form_cards.
* Tweak: readme now shows a Free vs Pro table.

= 1.2.0 =
* New: WPML support. Rules follow product and category translations, and rule names, the payment bonus label and the cart notice are translatable in WPML String Translation.
* Fix: a discount is now always a real discount. The "markup original price" mode showed an invented, higher regular price and charged the full price, and with an online payment bonus it charged cash on delivery customers more than the product price. That mode is removed: the strikethrough price is the product's real regular price and the lower price is what the customer pays.
* Fix: a fixed amount discount is no longer treated as a percentage in the price display (a fixed 50 showed "50% off" and a doubled price, a fixed 150 showed "150% off").
* Fix: the cart total no longer switches between two values on every reload when a rule has a minimum or maximum cart total. The limits now use the cart subtotal before discounts.
* Fix: "Application Method: Cart Total Fee" now works. A rule that is not shown on shop pages is taken off as a discount line in the totals.
* Fix: a fixed amount discount is taken off every unit in the cart and in the savings notice, so the "You saved" text matches the real saving.
* Fix: bank transfer and cheque payments no longer get the "online payment" bonus.
* Fix: discount line names with "&" or "<" were escaped twice and shown wrongly.
* Fix: the maximum discount applies to every item the same way in the shop prices, the cart item prices and the discount lines.
* Tweak: new name "Dynamic Discount Rules for WooCommerce", license header and translator comments added, translations load automatically on WordPress.org.
* Tweak: readme "Tested up to" and "Stable tag" updated.
