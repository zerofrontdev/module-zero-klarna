# Zero_Klarna

Klarna (pay now, pay later, in instalments, as Klarna offers the customer)
on Klarna's Hosted Payment Page, for Zero Checkout. No libraries: Klarna's
Payments, Hosted Payment Page and Order Management APIs over Magento's HTTP
client.

## Install

Requires Magento Open Source 2.4.7–2.4.9 and PHP 8.2–8.4 with the GD extension built with WebP (`php -r 'var_dump(function_exists("imagewebp"));'` says `true`; Magento's own PHP requirements cover the rest). Without WebP in GD, Zero skips its WebP image copies and serves the originals.

```bash
composer require zerofront/module-zero-klarna
bin/magento setup:upgrade
bin/magento setup:di:compile           # production mode
bin/magento setup:static-content:deploy # production mode
bin/magento cache:flush
```

Composer installs `zerofront/module-zero-checkout` with it.

Part of [`zerofront/zero`](https://zerofront.dev), which installs the Zero theme and every Zero module at once.

## How a payment goes

1. **Placing the order** creates a Klarna Payments session with the order's
   lines (`Model\OrderLines`: items, shipping, a last line for any cent of
   rounding; prices with tax, no tax breakdown, in minor units of the order's
   currency) and a Hosted Payment Page session that places the Klarna order
   itself (`place_order_mode` PLACE_ORDER). The Magento order waits in
   **pending payment**; Zero Checkout sends the customer to Klarna.
2. **Back from Klarna** (`zero_klarna/payment/return`; cancel and back go to
   `zero_klarna/payment/cancel`): the session is read from Klarna. COMPLETED
   with a Klarna order that Order Management shows AUTHORIZED for the
   order's amount and currency: the Magento order is **authorized**
   (processing, no invoice yet), the order email goes out. CANCELLED, FAILED,
   ERROR: cancelled, cart restored. Not finished: the session is disabled,
   the order cancelled. The cancel URL asks Klarna first too: an order
   already paid or authorized (another tab, the webhook) is never voided
   there, the customer sees the success page.
3. **status_update** (`zero_klarna/payment/callback`): for a customer who
   paid and never came back; the session is read from Klarna again. Not sent
   from a development site.
   Handled now or from the queue: Zero Checkout > Payment Webhooks
   (the Zero_Checkout README), under the order's lock. A Klarna order placed
   for a Magento order already cancelled is not booked: the order stays
   cancelled, with one comment and one admin notice
   (`OrderUpdater::latePayment()` only asks). The updater is registered in
   `RedirectPayment\Updaters` (NOT_STARTED alone never cancels), so the
   recheck cron (off by default) asks Klarna about pending orders.

**Capture:** invoice the order with **Capture Online** (in part too):
Klarna takes the money then (`captures`). **Cancel** before capturing
releases the authorization (`cancel`). **Refunds:** credit memos from a
captured invoice (`refunds`).

## Settings

Stores > Configuration > Sales > Payment Methods > **Klarna (Zero)**:
Enabled, Title, **Region** (Europe, North America, Oceania), **API
Username** and **API Password** (encrypted; Klarna merchant portal >
Settings > Klarna API keys), **Playground** (on by default: test
credentials), countries, sort order. Klarna offers itself only in the
countries and currencies of the merchant account.

## Tests

`Test/Unit/Model/OrderLinesTest.php`: the lines add up to the order amount.

What changed in each release: [CHANGELOG.md](CHANGELOG.md).

## License

OSL-3.0 or AFL-3.0, your choice: see `NOTICE.md`.

---

<a href="https://vilmate.com"><picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/zerofrontdev/zero/main/docs/images/vilmate-logo-dark.svg">
  <img src="https://raw.githubusercontent.com/zerofrontdev/zero/main/docs/images/vilmate-logo.svg" alt="Vilmate" height="34" align="right">
</picture></a>

Zero is made by [Fixstacks](https://fixstacks.com) in partnership with [Vilmate](https://vilmate.com), a software development company.
Documentation: https://zerofront.dev/docs. Demo store: https://demo.zerofront.dev.
