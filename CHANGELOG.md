# Changelog: zerofront/module-zero-klarna

## 1.3.6

- Returns, cancellations and webhooks settle through Zero Checkout's new flow, under the order's lock: a customer who paid in another tab is never cancelled by the cancel page.
- A Klarna order placed for an order already cancelled is flagged for the admin, never captured.
- Pending orders can be rechecked by Zero Checkout's recheck cron (off by default).
- Amounts use Klarna's minor units per currency (JPY, KRW and ISK whole); refunds carry a key derived from the credit memo.

Earlier releases (1.3.5 and before): see the Git tags.
