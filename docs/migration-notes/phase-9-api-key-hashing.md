# Phase 9 — API key storage hardening

New API keys are returned once at creation and stored only as SHA-256 digests. Existing keys are migrated to digests; a temporary encrypted copy is retained only for those legacy keys so the migration can be rolled back. Newly created keys are never escrowed and cannot be recovered from the database. Public API clients must send `Authorization: Bearer <key>` or `X-API-Key: <key>`; query-string credentials are rejected.

After deployment, merchants can continue using existing keys. The API key screen displays only a masked suffix. If this migration is rolled back, legacy keys can be restored, while keys created after migration must be rotated because the original plaintext is intentionally unavailable.

Validation: `FrontendContractEndpointsTest` verifies one-time display, masked listing, hashed header authentication, query-string rejection, and tenant-scoped management.
