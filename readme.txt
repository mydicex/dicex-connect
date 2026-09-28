=== DiceX Connect – Customer Club and Event Notifications ===
Contributors: arianashargh
Tags: customer club, crm, sms, whatsapp, woocommerce
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.8.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A customer club with levels you define, plus SMS, WhatsApp and Telegram alerts for orders, forms and logins, through your own DiceX account

== Description ==

DiceX Connect keeps your customers and your site's messages together, in your own DiceX account. Three parts, each switched on by itself:

* **A customer club** — your WordPress users and WooCommerce customers join your DiceX club, in levels you define, and move between them as they buy.
* **Your site's events** — a new order, a submitted form, a failed login: each can start a message.
* **The messages** — by SMS, WhatsApp, Telegram and more, to you, to your customer, or to both.

= Customer Club =

* **Levels you define** — by spend, orders, days since the last purchase, age, categories, products, brands, country or city. Each customer sits in the first level they meet.
* **Groups beside the levels** — a customer is in every group they match, such as coffee buyers or shoppers from Tehran.
* **Messages that fit** — a welcome on joining and a note on moving up a level, in the customer's own language.
* **Date of birth** — asked at checkout, on registration and in the account, in the Solar Hijri or the Gregorian calendar.
* **Consent and control** — everybody joins, or only those who tick the box, and anyone can leave from their account. The first import waits until you have checked the counts.
* **Big stores** — `wp dicex club` imports, syncs and reports from the command line.

= Messages for your site's events =

* **WooCommerce order notifications** — on the order statuses you choose, High-Performance Order Storage included.
* **Form notifications** — WPForms, Contact Form 7 and Gravity Forms. A successful submission notifies you, and can notify the person who filled the form in.
* **Security alerts** — repeated failed logins from one address, an administrator signing in, a new user, a password reset. No security plugin needed.
* **Two-step login** — a one-time code on the login screen, after the password or instead of it. One line in `wp-config.php` turns it off, so a lost phone never locks you out.
* **Your recipients, your words** — send to admin numbers, to administrators with a mobile on their profile, or to the customer the event is about. Each card has its own text, with tags such as `{order_id}` and `{site_name}`.
* **More than SMS** — the same messages can go over WhatsApp, voice call, Telegram, Bale or Safir, depending on what your DiceX account has.

= It stays out of the way =

A card that is switched off registers nothing, and one whose plugin is missing is greyed out. Messages go out after the visitor's page has loaded. If the gateway is unreachable, the failure is logged, and the order still completes and the form still submits.

= It speaks your language =

The screens follow WordPress's language, with translations from translate.wordpress.org, and read right to left in Persian, Arabic and Hebrew. Mobile numbers take a plus and the country code, like +96891234567, and Persian or Arabic-Indic digits are understood.

== External services ==

This plugin relies on **DiceX**, a third-party messaging, customer club and payment service. It is the only external service the plugin contacts, and nothing is sent until you paste your own API key into the settings or use the Support tab. Installing and activating the plugin sends nothing anywhere.

Every request goes to `https://gateway.dicex.me/` over HTTPS, with your API key and a User-Agent naming the plugin, WordPress, PHP and WooCommerce versions, the site language, the region and the site address.

What is sent, and when:

* **When you save an API key, open the Credit tab, or load the Sender lines tab:** your API key. DiceX answers with the account balance, the plan, and the sender lines the account owns.
* **When a notification fires, or you press "Send test":** the recipient's mobile number, the text of the message, and the sender line it goes out from.
* **When you start an online top-up:** the amount, the bank you picked, and the address on your own site to return to after payment. DiceX answers with the payment link.
* **Only if you switch the Customer Club on:** each customer's mobile number, name, club level, first purchase date and date of birth — and their email, company or billing address only if you tick those — when orders, refunds and profiles change, and once a day. The same details place them in the contact groups you define, and the club's welcome and level messages go out like any notification.
* **When you send the Support tab's form:** an email to wp-support@dicex.me, by your site's own mail: what you wrote, your email, the site's name, address and versions and, unless you untick it, the plugin's log, with email and IP addresses shortened.

Nothing else leaves your site. The plugin adds no analytics, tracking or telemetry, contacts no other host, and loads no script, style or font from a CDN.

DiceX links:

* Service homepage: https://dicex.me/
* Terms of service: https://dicex.me/terms
* Privacy policy: https://dicex.me/privacy
* Create an account: https://kyc.dicex.me/
* Account panel: https://app.dicex.me/
* API keys: https://dev.dicex.me/developers/api-keys
* Top up, and where a coupon is applied: https://home.dicex.me/financial-operations/user-charge
* Refund policy for international payments: https://ntft.tech/en/refund
* Privacy policy for international payments: https://ntft.tech/en/privacy
* What this plugin does: https://wp.dicex.me/

A top-up made through an international gateway is sold by New Taste For Technology and Investment LLC of Muscat, Oman, and the Credit tab shows its refund and privacy terms before you pay. By connecting an account you use DiceX under its terms, so read them and the privacy policy first. The rest are ordinary links in the admin screens; the plugin sends nothing to them.

== Bundled assets ==

The plugin ships one font, **Vazirmatn** by the Vazirmatn Project Authors, under the SIL Open Font License 1.1 (licence text in `assets/fonts/OFL.txt`). WordPress has no Persian face, so the plugin serves this one from its own folder; no font CDN is contacted.

== Installation ==

1. In your dashboard go to Plugins > Add New, search for "DiceX Connect", then click Install Now and Activate.
2. Open the new **DiceX** menu. Getting started links to creating an account and to fetching an API key; paste the key under the Connection tab.
3. Under the Sender lines tab, pick the line each channel should use, and send a test message to confirm it works.
4. Under the Integrations tab, switch on the plugins you want notifications from.
5. Under the Customer Club tab, set your levels and switch the club on.

To install from a ZIP: Plugins > Add New > Upload Plugin, then Activate and follow steps 2 to 5.

== Frequently Asked Questions ==

= Do I need a DiceX account? =

Yes. DiceX is a separate service, it is not free, and the plugin cannot send anything without an account and an API key of your own. Create one at https://kyc.dicex.me/ and read the terms at https://dicex.me/terms first.

= Which channels can it send over? =

SMS, WhatsApp, voice call, Telegram Bot, Bale Bot and Safir, depending on what your DiceX account and your region cover. Each card is given an ordered list of channels and tries the next one when the first does not get through.

= How does it know the customer's phone number on a form? =

It reads the submitted values and picks the one that is a usable mobile number for your region. A field labelled like a phone field wins over any other. There is nothing to map, on any form.

= Does the Customer Club need WooCommerce? =

No. Without it the club takes in WordPress users by the mobile number on their profile. Levels can go by age, and you can place a member in a level yourself. Conditions on purchases, products and place need WooCommerce.

= Can my customers get messages too, not just the admins? =

Yes. Each card chooses its recipients separately: a list of admin numbers, WordPress administrators with a mobile number on their profile, the customer or form submitter, or any combination.

= What is the Region setting for? =

DiceX does not sell the same services everywhere. The region you pick — Iran, GCC or World Wide — decides which channels and which payment gateways the plugin offers you. It is only named in the User-Agent of requests.

== Screenshots ==

1. Getting started — the customer club and the messages, DiceX's channels, and the two ways in: a new account, or an API key.
2. The Connection tab — your API key, the region, the login code length, the voice call language and the name in messages.
3. The Integrations tab — a card for each plugin DiceX can listen to; one whose plugin is missing is greyed out.
4. A card's settings — the channels in the order to try them, the order statuses that send, who is notified, and the message.
5. The Sender lines tab — choose a line per channel, or leave SMS, voice and Safir on the DiceX shared line, and send a test message.
6. The Credit tab — top up online through Saman, Sepehr or Digipay.
7. The Customer Club tab — where the club stands, what is waiting, and the levels you define, first match wins.
8. A level's conditions — spend, orders, days since the last purchase, age, and what a customer bought and where.

== Changelog ==

= 1.8.2 =

A Persian screen gets back the line saying what a Gulf top-up is worth elsewhere. The full notes, and every earlier release, are in changelog.txt.

== Upgrade Notice ==

= 1.8.2 =
On a Persian screen with the region set to GCC, the Credit tab shows again what an amount is worth in dollars and euros.
