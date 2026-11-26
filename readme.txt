=== AfriHex Address for WooCommerce ===
Contributors: yeboah70
Tags: ghana, address, ghanapostgps, checkout, delivery
Requires at least: 6.4
Tested up to: 7.2
Requires PHP: 7.4
WC requires at least: 8.6
WC tested up to: 11.3
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Ghana checkout addresses: customers enter a GhanaPostGPS code, place or landmark directions; each order gets a checked location and rider directions.

== Description ==

Ghanaian addresses are given as GhanaPostGPS codes ("GA-183-8164") or as directions ("opposite Goil, Madina Zongo Junction"). Street-and-number address forms lose both. This plugin adds a **Delivery location** field to the checkout:

* Type a GhanaPostGPS code, a place name, or landmark directions; suggestions appear as you type.
* Or tap "Use my current location".
* The chosen spot shows on a small map. The customer can drag the pin to their gate.
* When the order is placed, the location is checked on your server with AfriHex (`POST /v2/address/validate`) and saved on the order: GhanaPostGPS code, coordinates, verdict (verified / confirm with customer / could not locate), landmark, and entrance details such as "2nd gate · blue gate".
* The location appears on the admin order page, in order emails, on the customer's order page, and in the REST API (`afrihex_location` on `/wp-json/wc/v3/orders`), so delivery partners get it.

If AfriHex can't be reached, checkout is **not** blocked: the order is saved with what the customer typed, marked "Not checked yet", and checked again automatically (after 5 minutes, 30 minutes and 3 hours). You can also re-check from the order page, optionally with a corrected GhanaPostGPS code.

Works with both the block-based Checkout and the classic `[woocommerce_checkout]` shortcode, and with High-Performance Order Storage.

= External service =

This plugin uses the AfriHex API (https://api.afrihex.com), operated by AfriHex.

* In the customer's browser, as they type: what they type, and their location if they tap "Use my current location", is sent with your **publishable** key to look up suggestions.
* On your server, when an order is placed: the delivery location the customer chose and the order's address lines are sent with your **secret** key to check them.
* The map loads tiles from the tile server set in the settings (OpenStreetMap by default, https://www.openstreetmap.org, tile usage policy: https://operations.osmfoundation.org/policies/tiles/).

The map uses Leaflet 1.9.4 (BSD-2-Clause, https://leafletjs.com), bundled in assets/vendor/leaflet.

AfriHex terms: https://afrihex.com/terms — privacy: https://afrihex.com/privacy

== Installation ==

1. Upload the `afrihex-address-for-woocommerce` folder to `/wp-content/plugins/`, or upload the zip under Plugins → Add New → Upload.
2. Activate it. WooCommerce must be active.
3. Go to **WooCommerce → AfriHex Address**.
4. Paste your publishable key (`afx_pub_…`). In the AfriHex dashboard, add your shop's address (shown on the settings page, e.g. `https://myshop.com.gh`) to that key's websites.
5. Paste your secret key (`afx_live_…`) and, for testing, your test key (`afx_test_…`). Click **Test secret key**.
6. Open your checkout. The field appears under the shipping address; nothing needs to be added to the page.

Secret keys can instead be set in `wp-config.php`:

`define( 'AFRIHEX_SECRET_KEY', 'afx_live_…' );`
`define( 'AFRIHEX_TEST_SECRET_KEY', 'afx_test_…' );`

== Frequently Asked Questions ==

= Is my secret key sent to the browser? =

No. Only the publishable key, which only works from your shop's own web address and only for lookups, is put in the checkout page. The secret key is used from your server and is never printed into any page, including the settings page.

= What happens if AfriHex is down? =

The order goes through. What the customer typed is saved, the order is marked "Not checked yet", and the check is retried automatically. Nothing is lost.

= Can I stop orders with an address that can't be found? =

Yes: tick "Ask the customer to correct an address AfriHex cannot locate". By default such orders go through and are flagged "Could not locate" for you to follow up.

= What is removed when I delete the plugin? =

Its settings and scheduled retries. Locations saved on past orders are kept. To remove those too, add `define( 'AFRIHEX_REMOVE_ALL_DATA', true );` to `wp-config.php` before deleting.

== Screenshots ==

1. The field at checkout with the chosen place and draggable pin.
2. Suggestions for a GhanaPostGPS code.
3. The delivery location on the admin order page.
4. Settings.

== Changelog ==

= 0.1.1 =
* Tested with WordPress 7.2 and WooCommerce 11.3.
* Tested with PHP 8.6.
* The plugin header now declares WooCommerce 11.3 compatibility, so WooCommerce no longer shows an "untested" notice.

= 0.1.0 =
* First version.
