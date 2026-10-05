# Platform research: OpenCart

## Status
Research workspace. The supplied wipay_opencart.ocmod.zip is retained at the repository root as legacy reference only.

## Legacy package facts
Its README identifies version 1.1 for OpenCart 3.0.3.2 and GPLv3. It includes WiPay Credit and Voucher payment methods and instructs merchants to add an old .htaccess callback rewrite. Re-evaluate routing and callback security; do not install or ship it as current.

## Current direction
WiPay's current Payments API uses a hosted payment page. Create requests server-side, redirect shoppers, then verify response hash and amount before changing order status. OpenCart 4 packaging/layout differs from 3.x, so explicitly choose supported versions.

## Next decisions
- OpenCart 3.x, 4.x, or both?
- Confirm reuse/license with WiPay; validate sandbox fields, hash, endpoint/territory, and voucher support.
- Specify callback route, retry/idempotency, and order-status mapping.

## References
- [WiPay Payments API](https://docs.wipayfinancial.com/payments-api)
- [OpenCart extension development](https://docs.opencart.com/developer-guide/extensions)
- [WiPay OpenCart integration page](https://wipaycaribbean.com/integrations/opencart)
