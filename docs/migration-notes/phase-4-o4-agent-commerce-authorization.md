# Phase 4 O4: Agent-scoped commerce authorization

Commerce resolution rejects inactive agents and only returns connected stores
enabled for the active agent. AI tool payloads cannot select a store; product
and status actions use the resolved conversation context. No schema change is
required.

Verification: `php artisan test --filter=CommerceContextResolverTest`.

Rollback: revert the resolver authorization check and the associated tests.
