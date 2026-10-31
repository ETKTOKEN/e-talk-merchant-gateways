=== E-TALK Merchant Gateways ===
Contributors: etalk
Tags: woocommerce, payment gateway, bnpl, payments, webchain
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept E-TALK V1 Card and Buy Now Pay Later (BNPL) payments in WooCommerce.

== Description ==
E-TALK Merchant Gateways provides two payment options for WooCommerce merchants with an E-TALK merchant account.

* **V1 Card:** Customers enter their WebChain wallet address and V1 Card CCV, request an email verification code, and authorize the payment. An approved payment updates the WooCommerce order.
* **E-TALK BNPL:** Customers sign in to their E-TALK account, confirm a six-digit email verification code, and complete an eligibility check before paying. Approved purchases create an E-TALK C-Lock; the merchant payment is recorded as pending USDC under E-TALK's settlement rules.
* **Checkout support:** Provides WooCommerce payment methods for Checkout Blocks and Classic Checkout.
* **Independent identities:** The WooCommerce billing email need not match the E-TALK payer's account email.

These gateways require access to E-TALK's remote payment services. The plugin transmits the payment amount, merchant authentication, order reference, and the details necessary for the selected payment method to E-TALK for authentication, eligibility checks, and payment processing. BNPL requires the E-TALK account credentials and email verification code during customer authentication. V1 Card requires a wallet address, CCV, and email verification code. Configure and use the service only in accordance with your applicable privacy and merchant obligations.

== Installation ==
1. Install and activate WooCommerce.
2. Upload and activate E-TALK Merchant Gateways.
3. Go to WooCommerce > Settings > Payments.
4. Enable the desired E-TALK gateway and enter the merchant credentials issued by E-TALK.
5. Confirm the merchant connection and complete a payment in an environment appropriate for your business before accepting customer orders.

== Frequently Asked Questions ==
= Do customers need a WooCommerce account to use E-TALK? =
Availability depends on the WooCommerce store's checkout configuration and the payment method. BNPL customers must authenticate to their E-TALK account.

= Does the WooCommerce billing email have to match the E-TALK account email? =
No. The E-TALK payer is authenticated separately from the WooCommerce billing contact.

= When are BNPL funds available to merchants? =
BNPL payments are recorded as pending USDC and become eligible according to E-TALK's C-Lock and merchant payout rules. Payout availability is shown in the E-TALK merchant dashboard.

= What happens if the payment request times out? =
Do not immediately submit another payment. Check the WooCommerce order and E-TALK merchant transaction records to avoid charging the customer twice.

== Screenshots ==
1. E-TALK BNPL option at WooCommerce checkout.
2. E-TALK V1 Card option at WooCommerce checkout.

== Changelog ==
= 1.0.0 =
* Initial public package with E-TALK V1 Card and BNPL integrations for WooCommerce.
* Includes customer verification, checkout payment authorization, and Checkout Blocks support.
