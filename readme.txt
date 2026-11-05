=== E-TALK Merchant Gateways ===
Contributors: ikobopay
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

== External services ==

This plugin depends on the external E-TALK LTD payment services hosted at https://e-talk.xyz. These are remote APIs operated by the plugin provider, not by WordPress.org or the WooCommerce store. When a merchant configures a gateway, or a customer chooses an E-TALK payment method and submits the corresponding form, information is transmitted directly from the merchant's WordPress server to E-TALK over HTTPS. Neither gateway contacts E-TALK merely to display a payment option. The service is required for the corresponding payment method to operate.

= E-TALK V1 Card service =

Provider: E-TALK LTD. API: https://e-talk.xyz/api/v1-card/v1/

* When the merchant selects Verify Connection, the plugin sends the configured merchant ID and merchant API key to the service's status endpoint to authenticate the gateway configuration. This does not create a payment.
* When a customer requests payment verification, the plugin sends their WebChain wallet address (V1 card number), three-digit CCV, transaction amount, currency, unique order reference, merchant ID and merchant API key to request-payment-otp. E-TALK sends an email verification challenge to the cardholder.
* When the customer confirms payment, the plugin sends the wallet address, CCV, emailed 64-character hexadecimal verification code, transaction amount, currency, order reference, merchant ID and merchant API key to process-payment. E-TALK validates authorization and processes the payment. The returned transaction identity is used to update the WooCommerce order.
* The wallet address, CCV and verification code are entered for payment authorization; merchants should not collect or reuse these details for unrelated purposes. The plugin does not intentionally put the CCV or code in WooCommerce order notes or metadata.

V1 Card integration guide: https://e-talk.xyz/v1-card-integration-docs/

= E-TALK Buy Now Pay Later (BNPL) service =

Provider: E-TALK LTD. API: https://e-talk.xyz/api/bnpl/v1/

* When the merchant selects Verify Connection, the plugin sends the configured merchant API key and an HMAC signature derived from the merchant API secret to the service's status endpoint. This checks the configuration without creating a purchase.
* When a customer starts BNPL authentication, their E-TALK email and password are sent to verify-user to authenticate their existing E-TALK account and start an email OTP challenge. When they enter the six-digit OTP, the plugin sends the OTP and temporary authentication token to verify-otp. The plugin holds the resulting short-lived bearer session on the merchant server for the checkout; the customer password is not intentionally retained.
* When the customer checks BNPL eligibility, the plugin sends the cart amount and the verified customer's bearer session to check-eligibility. This determines eligibility and does not create a purchase.
* When the customer authorizes a purchase, the plugin sends the order amount, USD currency, unique order reference, signature generated with the merchant secret, merchant API key and verified customer's bearer session to external-purchase. E-TALK processes the BNPL authorization, C-Lock accounting and pending merchant settlement. The returned merchant transaction identity and settlement status are recorded on the WooCommerce order.

BNPL merchant policy: https://e-talk.xyz/docs/?doc=bnpl-merchant-policy
BNPL and V1 API details: https://e-talk.xyz/v1-card-integration-docs/

= Terms and privacy for both services =

E-TALK LTD Terms of Service: https://e-talk.xyz/terms-of-service/
E-TALK LTD Privacy Policy: https://e-talk.xyz/privacy-policy/

Merchant stores must also provide their own applicable checkout privacy notices and obtain any consent required by law. Payment and account data is sent to E-TALK only as described above when a merchant verifies a connection or a customer initiates the applicable checkout steps. These services are not described as analytics or advertising tracking services.

== Source code and development ==

The complete, human-readable JavaScript and CSS source is included in this plugin:

* assets/blocks-dev.js - WooCommerce Checkout Blocks registration and payment verification panels for V1 Card and BNPL.
* assets/verify-connection.js - Merchant connection verification controls.
* assets/merchant-settings.js - Merchant logo selection using the WordPress Media Library.
* assets/v1-test.js - V1 Card verification-code request controls for Classic Checkout.
* assets/merchant-ui.css - Gateway settings and checkout presentation.

These files are loaded directly by the plugin. There are no separate minified bundles, JSX or TypeScript sources, or source maps required to modify them. No compilation or build step is required: edit the included source files and test them in WordPress with WooCommerce. The plugin uses the JavaScript dependencies supplied by WordPress and WooCommerce rather than bundling copies of those libraries.

Development repository: https://github.com/ETKTOKEN/e-talk-merchant-gateways

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
* Pre-approval review corrections: include readable JavaScript and CSS, document source locations, external API data transfers, terms, privacy and merchant policies, and correct the WordPress.org contributor name. Payment processing is unchanged.
* Initial public package with E-TALK V1 Card and BNPL integrations for WooCommerce.
* Includes customer verification, checkout payment authorization, and Checkout Blocks support.
