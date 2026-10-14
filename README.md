# Waypoint WiPay for OpenCart

An independent WiPay hosted-card-checkout extension for OpenCart, maintained by Waypoint. It is not affiliated with, sponsored by, or endorsed by WiPay.

Shoppers are sent to WiPay's hosted payment page to enter their card, then returned to the store, where the result is verified server-side before the order is marked paid.

## Status

**Sandbox testing release.** The request/response contract is implemented from WiPay's published Payments API documentation and unit-tested (`php tests/run-tests.php`, 205 assertions). It has **not yet been exercised against WiPay sandbox or live**. Work through [TESTING.md](TESTING.md) before relying on it.

Live payment processing has never been tested. Installers must keep the gateway in Sandbox.

## Versions

| Directory | OpenCart | Package layout |
|---|---|---|
| `opencart3/upload/` | 3.0.3.x | Files dropped into the existing `catalog/` and `admin/` trees |
| `opencart4/upload/extension/waypoint_opencart/` | 4.0.x / 4.1.x | A self-contained extension package with `install.json` |

The two versions share the same security design and the same WiPay contract; they are separate codebases because OpenCart's controller, model and template APIs changed substantially between the two majors.

## Safety design

- The checkout request amount is read from the OpenCart order record on the server and pinned before the API request.
- Successful payment requires the transaction id recorded when the attempt began, a valid WiPay hash calculated from the pinned order total, and an order still sitting in its pending status.
- The callback reads nothing it needs from the query string: order id, pinned total and environment all come from the recorded attempt row.
- A missing or invalid hash leaves the order unpaid.
- A late successful payment for an order that has already moved on is recorded on the order as a note for manual review rather than silently re-completing it.
- Failed or cancelled responses only move an order that is still pending.
- The live response key is never written to the settings table. It is read from a constant defined in `config.php`.

## Installation

### OpenCart 3

1. Copy the contents of `opencart3/upload/` over your OpenCart root.
2. In **Admin → Extensions → Extensions → Payments**, install **WiPay**.
3. Edit it, set **Country**, save, then set **Status** to *Enabled*.

The attempt table `wipay_waypoint_attempt` is created lazily on the first checkout, so no separate migration step is needed.

### OpenCart 4

1. Zip the contents of `opencart4/upload/extension/waypoint_opencart/` so that `install.json` sits at the root of the archive.
2. In **Admin → Extensions → Installer**, upload and install it.
3. In **Admin → Extensions → Extensions → Payments**, enable **WiPay**.
4. Edit it, set **Country**, and save.

### Configuration

| Setting | Notes |
|---|---|
| Country | The territory your WiPay account is registered in: `BB`, `GD`, `GY`, `JM` or `TT`. This selects the host the request is sent to. |
| Environment | **Sandbox** by default. |
| Fee structure | `merchant_absorb`, `customer_pay` or `split`. |
| Live account number | Used only in Live mode. Sandbox always uses WiPay's published test account. |

For Live mode, define the response key next to the other constants in your `config.php`:

```php
define( 'WIPAY_WAYPOINT_LIVE_API_KEY', 'wapi_live_...' );
```

It is never entered through the admin form and is never stored in the settings table.

## Hosts

| Territory | Sandbox | Live |
|---|---|---|
| Barbados | `bbsb.wipayfinancial.com` | `bb.wipayfinancial.com` |
| Grenada | `gdsb.wipayfinancial.com` | `gd.wipayfinancial.com` |
| Guyana | `gysb.wipayfinancial.com` | `gy.wipayfinancial.com` |
| Jamaica | `jmsb.wipayfinancial.com` | `jm.wipayfinancial.com` |
| Trinidad & Tobago | `ttsb.wipayfinancial.com` | `tt.wipayfinancial.com` |

The Trinidad & Tobago pair is published by WiPay. The others follow the documented host layout and must be re-confirmed against WiPay's Platforms and Environments page before a release. The request endpoint on every host is `/plugins/payments/request`.

If the configured country is not in this map, the extension refuses to send the request and tells the shopper the gateway is not configured for their territory, rather than failing silently.

## Testing

```
php tests/run-tests.php
```

The suite loads both the OpenCart 3 and OpenCart 4 models against stubbed OpenCart classes and runs the same battery over both, so the versions cannot drift on the parts that talk to WiPay. See [TESTING.md](TESTING.md) for the sandbox walkthrough.

## Ownership and disclaimer

Waypoint is an independent software maintainer. This extension is provided without any warranty, without guarantee of fitness for a purpose, and without any relationship to WiPay or OpenCart. Test thoroughly in a sandbox environment before taking real payments.
