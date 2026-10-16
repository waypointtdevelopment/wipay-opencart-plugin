# Platform research: OpenCart

## Status
**Resolved.** Both versions are built and unit-tested. The supplied wipay_opencart.ocmod.zip is retained at the repository root as legacy reference only.

## Legacy package facts
Its README identifies version 1.1 for OpenCart 3.0.3.2 and GPLv3. It includes WiPay Credit and Voucher payment methods and instructs merchants to add an old .htaccess callback rewrite. Re-evaluate routing and callback security; do not install or ship it as current.

## Current direction
WiPay's current Payments API uses a hosted payment page. Create requests server-side, redirect shoppers, then verify response hash and amount before changing order status. OpenCart 4 packaging/layout differs from 3.x, so explicitly choose supported versions.

## Decisions taken
- **Both versions shipped.** `opencart3/upload/` drops files into the existing catalog and admin trees; `opencart4/upload/extension/waypoint_opencart/` is a self-contained package with `install.json` at its archive root.
- **Callback security.** The response hash is verified against the pinned order total held in the `wipay_waypoint_attempt` ledger, never against the total returned in the query string.
- **Ledger table.** Created lazily on first checkout so a manual file upload works without an install hook.
- **Live key.** Read from `WIPAY_WAYPOINT_LIVE_API_KEY` in `config.php`, never stored in `oc_setting`.
- **No mbstring dependency.** Field capping uses a helper that falls back to byte-safe truncation, because mbstring is not a hard OpenCart requirement.
- **Vouchers dropped.** The legacy package's voucher method is out of scope for the rebuilt extension.

## OpenCart 4 API facts verified against source
- `Session` exposes a public `$data` array; read and write values as `$this->session->data['key']`. There is no per-key `read()`/`write()`.
- The payment model method is `getMethods(array $address = []): array` returning `code`, `name`, `option` and `sort_order` — note **`name`**, not `title` or `terms`.
- The confirmation controller renders the payment controller's default action; the payment button's own JavaScript calls `confirm()` over AJAX and navigates on a JSON `redirect` key.
- `addHistory(int $order_id, int $order_status_id, string $comment, bool $notify, bool $override)`. The order id is explicitly typed `int`; the return type varies between point releases, so it is not relied upon.
- Language and template routes include the `extension/<package>/` prefix.

## Remaining unknowns
- ~~Whether WiPay's non-TT territory hosts match the documented layout; only the TT pair is published. Re-confirm before release.~~ **Closed 15 October 2026:** all five live/sandbox pairs (BB, GD, GY, JM, TT) match WiPay's Platforms and Environments page, and all ten hostnames returned HTTP 200 when probed over HTTPS.
- Behaviour of the lazily created table on hosts with unusual MySQL permissions.

## References
- [WiPay Payments API](https://docs.wipayfinancial.com/payments-api)
- [OpenCart extension development](https://docs.opencart.com/developer-guide/extensions)
- [WiPay OpenCart integration page](https://wipaycaribbean.com/integrations/opencart)
