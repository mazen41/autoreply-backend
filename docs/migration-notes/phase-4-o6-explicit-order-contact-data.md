# Phase 4 O6: Explicit order contact data

Checkout no longer treats arbitrary address digits as a phone number and no
longer fills the shipping name from a social profile. Delivery country is
resolved from a known phone prefix or an explicit country in the address; an
unknown country does not silently become Saudi Arabia. The bot must collect
clarification before Salla shipping/order submission can proceed.

No schema change is required.

Verification: `php artisan test --filter='CheckoutOrderFlowTest|SallaServiceTest'`.

Rollback: revert the phone/country validation changes with their tests. This
restores the previous Saudi fallback and should not be used without an owner
decision because it can submit orders to the wrong country.
