# Phase 4 O3: Store-scoped product actions

AI `get_products` actions now use the conversation's authorized commerce context
and return provider product IDs plus the connection ID. An unresolved
multi-store conversation fails closed and returns no catalog. No schema change
is required.

Verification: `php artisan test --filter='ai_get_products|product_context_can_be_scoped'`.

Rollback: revert the ActionExecutor product identifier mapping and its tests.
