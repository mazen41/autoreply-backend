# Phase 4 O8: checkout summary and cancellation

## Change

Complete checkout now sends the customer a deterministic summary of the product, price and currency, store, name, phone, and delivery address. The sent summary must remain valid and precede the customer's confirmation. An explicit cancellation clears an unsubmitted checkout before AI or commerce-provider order code runs. A cancellation request for an order that already exists opens a high-priority handoff and does not claim the provider order was cancelled.

## Merchant impact

Customers see the order details and cancellation instruction before confirming. Pending checkout cancellation is self-service; cancellation of a placed order is routed to the team for handling.

## Verification and rollback

`CheckoutOrderFlowTest` covers cancellation before provider submission and handoff after placement. Rollback: revert the cancellation branch/tests; the summary behavior remains governed by O2.
