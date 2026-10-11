# Phase 3 remediation status

Last updated: 2026-10-11

## Summary

The 32 failures from the supplied baseline are resolved. The final complete backend run passed 487 tests with 2 skipped (2,039 assertions). Two additional failures surfaced in newly added tests during intermediate runs: a test fixture still expected a provider key from process environment, and sequence-step deduplication compared timestamps too precisely. Both were corrected and are covered by the final suite.

No production server, production database, credentials, or payment provider was accessed. `.env` secret values were not read. Both repositories now set `* text=auto eol=lf` in `.gitattributes`; only Git's configured normalization is used for the changed files.

## Triage: original 32 failures

| # | Test | Group / decision | Root cause and correction |
|---:|---|---|---|
| 1 | `BusinessFeatureFlagTest::test_message_safety_rollouts_are_listed_and_default_off` | A — update test | Owner decision enabled the rollout flags globally; assertions now expect global enabled state. `tests/Feature/BusinessFeatureFlagTest.php`. |
| 2 | `BusinessFeatureFlagTest::test_opt_in_flag_defaults_off_and_can_only_be_enabled_for_the_selected_business_with_audit` | A — update test | These are no longer business opt-in flags; assertions now verify global enablement and reject per-business overrides. Same test file. |
| 3 | `AiSetupVerificationTest::test_setup_verification_gate_is_off_until_the_business_opts_in` | A — update test | Setup verification is globally required by the rollout decision; tests assert setup cannot go live before verification. `tests/Feature/AiSetupVerificationTest.php`. |
| 4 | `WorkflowEngineRefactorTest::test_workflow_reply_safety_is_opt_in_per_business` | A — update test | Workflow reply safety is globally enabled; the test now asserts unsafe automation sends are blocked. `tests/Feature/WorkflowEngineRefactorTest.php`. |
| 5 | `BotKnowledgeRetrievalTest::test_no_bot_preserves_global_business_knowledge` | B — intentional fail-closed behavior | No assigned Bot now means no AI provider call or reply, with a `no_bot_assigned` handoff task. Test now checks that behavior. `tests/Feature/BotKnowledgeRetrievalTest.php`. |
| 6 | `HandoffTaskFlowTest::test_customer_request_for_a_human_creates_task_before_sending_the_promise` | B — fixture correction | The fixture had no active Bot, so global fail-closed routing prevented the handoff intent path from running. Added an assigned Bot; the task-before-promise contract remains. `tests/Feature/HandoffTaskFlowTest.php`. |
| 7 | `CustomerResolutionTest::test_instagram_conversation_unifies_with_whatsapp_customer_via_checkout_phone` | B — fixture correction | The conversation lacked an assigned Bot and never reached checkout phone resolution. Added the Bot fixture; customer unification assertion remains. `tests/Feature/CustomerResolutionTest.php`. |
| 8 | `AIPromptContextTest::test_prompt_states_unknown_customer_name_without_inventing_one` | B — fixture correction | No Bot meant there was no prompt to inspect. Added an active Bot; unknown names remain unknown. `tests/Feature/AIPromptContextTest.php`. |
| 9 | `ProductReplyContextTest::test_sending_product_images_persists_one_message_to_product_mapping_per_image` | B — fixture correction | No assigned Bot stopped image processing before message mapping. Added the active commerce Bot fixture. `tests/Feature/ProductReplyContextTest.php`. |
| 10 | `ProductReplyContextTest::test_reply_to_specific_product_image_resolves_exact_product_for_place_order` | B — fixture correction | No assigned Bot prevented referenced-product lookup. Added the active commerce Bot fixture. Same test file. |
| 11 | `ProductReplyContextTest::test_reply_to_non_product_message_does_not_fabricate_a_referenced_product` | B — fixture correction | No assigned Bot prevented the intended negative-path handling. Added the active commerce Bot fixture; no fabricated product is still expected. Same test file. |
| 12 | `CheckoutOrderFlowTest::it_does_not_claim_an_order_was_placed_when_no_salla_store_is_connected` | B — fixture correction | The test had no Bot, so it failed before reaching the “store unavailable” response. Added a Bot but kept Salla disconnected; the honest failure response remains required. `tests/Feature/CheckoutOrderFlowTest.php`. |
| 13 | `CheckoutOrderFlowTest::it_extracts_and_merges_address_without_overwriting_and_creates_order_on_explicit_confirmation` | C — fixture correction | The missing Bot prevented address collection, and the test's later assertion accessed the absent `address` key. The commerce Bot fixture now exercises the intended multi-turn flow. Same test file. |
| 14 | `CheckoutOrderFlowTest::it_handles_salla_422_failure_and_does_not_create_order_or_trigger_sequence` | C — fixture correction | The request never reached Salla because no active Bot was assigned. The test now reaches the mocked 422 path and verifies no order/sequence is created. Same test file. |
| 15 | `CheckoutOrderFlowTest::it_allows_retry_after_salla_failure_and_succeeds_when_api_returns_2xx` | C — fixture correction | No active Bot meant the Salla failure/retry path did not run. Added the commerce Bot fixture; retry and handoff assertions remain. Same test file. |
| 16 | `SallaOrderStateRecoveryTest::it_persists_checkout_state_across_turns_and_recovers_when_api_fails` | C — fixture correction | No active Bot prevented the checkout-state flow. Added a Bot bound to the Salla connection. `tests/Feature/SallaOrderStateRecoveryTest.php`. |
| 17 | `SallaAggregateQueryTest::test_list_products_query_avoids_business_profile_contamination` | C — fixture correction | No assigned Bot/channel store route prevented the aggregate query. Added scoped commerce fixtures. `tests/Feature/SallaAggregateQueryTest.php`. |
| 18 | `SallaAggregateQueryTest::test_order_count_query_calls_salla_orders_list_endpoint` | C — fixture correction | Same missing Bot/store route; fixture now reaches the orders endpoint. Same test file. |
| 19 | `SallaAggregateQueryTest::test_product_count_query_calls_salla_products_list_endpoint` | C — fixture correction | Same missing Bot/store route; fixture now reaches the product endpoint. Same test file. |
| 20 | `SallaAggregateQueryTest::test_salla_product_fetch_failure_reports_honest_error_not_generic_no_access` | C — fixture correction | Same missing Bot/store route; test now exercises the provider failure response. Same test file. |
| 21 | `SallaImageSendTest::test_false_image_claim_is_overridden_when_no_image_available` | C — fixture correction | No active Bot/store route prevented the image candidate logic. Added scoped commerce fixtures. `tests/Feature/SallaImageSendTest.php`. |
| 22 | `SallaImageSendTest::test_false_image_claim_is_overridden_when_send_api_call_fails` | C — fixture correction | Same missing Bot/store route; now exercises the failed send path. Same test file. |
| 23 | `SallaImageSendTest::test_product_images_leave_one_plan_unit_for_the_customer_facing_text_reply` | C — fixture correction | Same missing Bot/store route; now exercises the image and text usage contract. Same test file. |
| 24 | `SallaImageSendTest::test_real_thumbnail_field_results_in_actual_image_send` | C — fixture correction | Same missing Bot/store route; now exercises a real thumbnail send. Same test file. |
| 25 | `SallaProductBrowseIntentTest::test_actual_buy_order_phrase_does_not_trigger_product_aggregate` | C — fixture correction | No assigned Bot/store route stopped intent handling before the assertion. Added scoped commerce fixtures. `tests/Feature/SallaProductBrowseIntentTest.php`. |
| 26 | `SallaProductBrowseIntentTest::test_arabic_show_me_products_triggers_aggregate` | C — fixture correction | Same missing Bot/store route; fixture now reaches Arabic browse aggregation. Same test file. |
| 27 | `SallaProductBrowseIntentTest::test_fetch_them_follow_up_after_rephrase_triggers_aggregate_not_escalation` | C — fixture correction | Same missing Bot/store route; fixture now exercises the follow-up. Same test file. |
| 28 | `SallaProductBrowseIntentTest::test_multi_turn_salla_flow_excludes_knowledge_base` | C — fixture correction | Same missing Bot/store route; fixture now exercises the intended multi-turn catalog path. Same test file. |
| 29 | `SallaProductBrowseIntentTest::test_natural_phrase_what_products_you_have_on_salla_triggers_aggregate` | C — fixture correction | Same missing Bot/store route; fixture now reaches product aggregation. Same test file. |
| 30 | `SallaProductBrowseIntentTest::test_show_me_what_you_sell_triggers_aggregate` | C — fixture correction | Same missing Bot/store route; fixture now reaches product aggregation. Same test file. |
| 31 | `WhatsAppInstanceIdTest::test_outgoing_message_creates_legacy_record_when_instance_exists` | C — fixture correction | No Bot assignment stopped processing before the provider-send contract. Added an active Bot; instance compatibility assertions remain. `tests/Feature/WhatsAppInstanceIdTest.php`. |
| 32 | `WhatsAppInstanceIdTest::test_outgoing_message_succeeds_without_whatsapp_instance_row` | C — fixture correction | Same missing Bot assignment; the send path now reaches the case with no legacy instance row. Same test file. |

## Additional regression found during verification

`SequenceHardeningTest::test_execution_key_prevents_duplicate_execution_records` found a real idempotency bug. Timestamp string comparison could miss an already-pending execution when microseconds differed. The pending-step check now runs under a row lock/transaction and returns the existing execution. The regression test passes.

The later-added `MultimodalImageAnalysisTest` also exposed an outdated test fixture after runtime config moved out of `env()`. The test now sets configuration and uses the private media disk; the uploaded file is verified removed.

## Owner decisions and billing implementation

- API-originated replies outside Meta's service window remain refused by default. Telegram and Gmail are not subject to that Meta-specific gate.
- Renewal is manual by default, with optional explicit monthly auto-renew through MyFatoorah managed recurring. Checkout stores consent time and the provider recurring identifier, the billing page can turn renewal off, provider renewal webhooks are signature-checked and invoice-idempotent, and MyFatoorah retries up to three times before the local three-day grace period.
- Annual checkout remains manual: MyFatoorah V2 managed recurring documents Monthly, Weekly, Daily, and Custom intervals capped at 180 days, not yearly. The recurring option is rejected until `MYFATOORAH_RECURRING_ENABLED=true`; set it only after MyFatoorah enables recurring for the account and confirms the supported payment methods. See [MyFatoorah recurring docs](https://docs.myfatoorah.com/docs/recurring-payment) and [recurring webhook payload](https://docs.myfatoorah.com/docs/webhook-v2-recurring-data-model).

## Verification and operator checklist

- Backend: `php artisan test` — 487 passed, 2 skipped, 2,039 assertions.
- Frontend: `node scripts/check-frontend-api-routes.mjs` — passed, 149 paths; `npx tsc --noEmit` — passed; `npm run build` — passed.
- Image analyzer: `ImageUnderstandingServiceTest` and `MultimodalImageAnalysisTest` pass after `php artisan config:cache`; config cache was cleared afterward.
- No production migration or API was run. Before deployment, on staging: run `php artisan migrate --pretend`, apply migrations to a staging copy, then run the relevant tests.
- Deploy must run `php artisan config:cache` and restart long-running queue workers after config changes.
- On production, verify presence only (never print values) of `META_APP_SECRET`, `SANCTUM_TOKEN_EXPIRATION`, and `MYFATOORAH_RECURRING_ENABLED`. Keep MyFatoorah recurring disabled until the provider confirms activation; then set the flag and rebuild config cache.
- Verify the four global flags from migration `2026_10_11_000001_enable_message_safety_rollout_flags_globally` match the owner decision.
- The six old worktrees have merged branches but contain dirty/untracked changes. They were not removed to avoid deleting state that is not yet proven redundant.

## Remaining external prerequisites

MyFatoorah account activation is required before auto-renew can be used. The renewal implementation has test coverage against mocked provider responses; no live payment, card, token, or recurring charge was initiated. Live recurring behavior must be verified in the provider sandbox after the merchant enables the feature.
