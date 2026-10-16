# Testing the WiPay OpenCart extension

The extension is unit-tested but has not yet been run against WiPay sandbox. Work through this list before treating it as usable. Test one OpenCart version at a time; the same scenarios apply to both.

## 1. Automated checks

```
php tests/run-tests.php
```

227 assertions covering the host map, credential selection, hash computation and verification, checkout-URL allowlisting, payload field rules and length caps, the attempt ledger, and the TTD minimum guard. This must be green before anything else.

## 2. Sandbox setup

1. Register a sandbox account with WiPay and note the published test values (`account_number` `1234567890`, `api_key` `123`). The extension uses these automatically when Environment is **Sandbox** — do not enter your own credentials.
2. Create an order in the store and confirm the shopper reaches WiPay's hosted page and can complete a card payment. Use the card set for the store's currency — WiPay publishes a TTD set and a separate USD/other-currencies set and states they are not interchangeable. For USD or any non-TTD store use `4242424242424242` (success) and `4000000000000002` (decline); for a TTD store use `4000000000002719` (frictionless, Status=A). WiPay publishes no plain decline card for TTD, so exercise the decline case with the store set to USD or use `4000000000002644` (Status=U, authentication error).
3. Confirm the shopper returns to the store and the order moves to the expected paid status.

## 3. Callback verification

This is the part that protects the store, so test each case deliberately.

| # | Scenario | Expected |
|---|---|---|
| 1 | Normal approved payment | Order moves to the paid status; the attempt row reads `paid`. |
| 2 | Remove the `hash` parameter from the callback URL | Order stays unpaid; response says the signature is invalid. |
| 3 | Change one character of the `hash` | Same as above. |
| 4 | Change the `total` parameter but keep the hash | Order stays unpaid — the hash is computed from the **pinned** total, not the returned one. |
| 5 | Change the `transaction_id` to another order's | Response says the transaction is unknown; the other order is untouched. |
| 6 | Replay a successful callback after the order is already paid | No duplicate status change; the attempt is marked `late`. |
| 7 | Cancel at WiPay's page | Order moves to the cancelled status, and only if it was still pending. |
| 8 | Fail at WiPay's page | Order moves to the cancelled status, and only if it was still pending. |

For cases 5, 6 and 8 you will need the real callback URL. Grab it from the order history note (`Waiting for WiPay payment. Transaction …`) plus the `response_url` the extension sent, or read the `wipay_waypoint_attempt` table.

## 4. Late-payment handling

1. Complete a payment, then cancel the order in admin.
2. Replay the approved callback URL by hand.
3. The order must **not** silently return to paid. Instead a note is added asking for manual review, and the attempt is marked `late`.

## 5. Configuration guards

| Setting | Expected behaviour |
|---|---|
| Country left blank | The confirm step refuses and tells the shopper the gateway is not configured. No request is sent. |
| Country set to a value outside the map | Same refusal. |
| Status disabled | WiPay no longer appears in the payment method list at checkout. |
| Environment switched to Live without `WIPAY_WAYPOINT_LIVE_API_KEY` | Saving is refused in admin, with a message naming the constant. |
| TT platform with a TTD order below 5.00 | Confirm refuses with "WiPay requires a minimum payment of TTD 5.00." and no request reaches WiPay. |

## 6. Per-version checks

**OpenCart 3.** Confirm the confirm button on the checkout summary posts to `index.php?route=extension/payment/wipay_waypoint/confirm` and that the browser follows the returned `redirect`.

**OpenCart 4.** Confirm the same, using the dotted route `extension/waypoint_opencart/payment/wipay_waypoint.confirm`. Confirm the extension appears in **Extensions → Extensions → Payments** after installing the zip, and that `install.json` was at the archive root.

## 7. What has not been verified

- ~~That WiPay's sandbox hosts for Barbados, Grenada, Guyana and Jamaica match the documented layout.~~ **Closed 15 October 2026:** all five live/sandbox pairs match WiPay's Platforms and Environments page, and all ten hostnames were probed and returned HTTP 200 over HTTPS.
- Whether the `wipay_waypoint_attempt` table creation succeeds on every host's MySQL configuration. Watch the error log on the first checkout after install.
- The exact `addHistory()` return type across every OpenCart 4 point release.
- Any behaviour under PHP versions other than the one you test on.

Report anything that disagrees with this document rather than working around it.
