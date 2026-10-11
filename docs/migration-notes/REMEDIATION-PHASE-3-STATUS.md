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

## Local commit groups and included files

### Backend 8d9b7ff — security: enforce tenant and integration safeguards
- .env.example
- app/Console/Commands/ApplyBusinessDataRetention.php
- app/Console/Commands/BackupDatabase.php
- app/Console/Commands/CheckUsageLimits.php
- app/Console/Commands/DailyAnalytics.php
- app/Console/Commands/MonitorFailedJobs.php
- app/Console/Commands/MonitorOperationalHealth.php
- app/Console/Commands/RenewGmailWatch.php
- app/Console/Commands/SystemHealthCheck.php
- app/Http/Controllers/Api/AdminController.php
- app/Http/Controllers/Api/AiActionsController.php
- app/Http/Controllers/Api/AiReplyAuditController.php
- app/Http/Controllers/Api/AiSetupVerificationController.php
- app/Http/Controllers/Api/AnalyticsController.php
- app/Http/Controllers/Api/ApiKeyController.php
- app/Http/Controllers/Api/BusinessController.php
- app/Http/Controllers/Api/CustomerController.php
- app/Http/Controllers/Api/DataRightsController.php
- app/Http/Controllers/Api/HandoffTaskController.php
- app/Http/Controllers/Api/KnowledgeController.php
- app/Http/Controllers/Api/OrdersController.php
- app/Http/Controllers/Api/PublicApiController.php
- app/Http/Controllers/Api/ReportsController.php
- app/Http/Controllers/Api/ToolsController.php
- app/Http/Controllers/Api/WebChatController.php
- app/Http/Controllers/Api/WebhookController.php
- app/Http/Controllers/GmailController.php
- app/Http/Controllers/N8nIntegrationController.php
- app/Http/Controllers/ShopifyController.php
- app/Http/Controllers/TikTokController.php
- app/Http/Controllers/WebhookController.php
- app/Http/Middleware/EnsureBusinessAccess.php
- app/Http/Middleware/PlanEnforcement.php
- app/Jobs/DeliverWebhook.php
- app/Jobs/ExportBusinessCustomerData.php
- app/Jobs/FetchSenderName.php
- app/Jobs/ProcessKnowledgeFile.php
- app/Jobs/SyncGmailHistory.php
- app/Models/AiActionLog.php
- app/Models/AiReplyAudit.php
- app/Models/AnalyticsDaily.php
- app/Models/ApiKey.php
- app/Models/BusinessKnowledgeFile.php
- app/Models/BusinessProfile.php
- app/Models/DataRightsRequest.php
- app/Models/HandoffTask.php
- app/Models/Package.php
- app/Models/Suppression.php
- app/Models/Webhook.php
- app/Providers/AppServiceProvider.php
- app/Services/AICapabilitiesService.php
- app/Services/ActionExecutor.php
- app/Services/ActiveBusiness.php
- app/Services/AgentCoachingService.php
- app/Services/AiReplyAuditService.php
- app/Services/AiSetupVerificationService.php
- app/Services/AnalyticsService.php
- app/Services/AuditLoggerService.php
- app/Services/BlogAIService.php
- app/Services/BusinessAccessService.php
- app/Services/ChurnDetectionService.php
- app/Services/ConditionEvaluationService.php
- app/Services/EmbeddingsService.php
- app/Services/EvolutionApiService.php
- app/Services/GoogleCalendarService.php
- app/Services/HandoffTaskService.php
- app/Services/KnowledgeFileIngestionService.php
- app/Services/MetaMessengerService.php
- app/Services/NotificationService.php
- app/Services/PerformanceOptimizationService.php
- app/Services/ScheduledFailureNotifier.php
- app/Services/WebhookService.php
- app/Support/OperationalMetricCounter.php
- app/Support/SensitiveLogContext.php
- bootstrap/app.php
- composer.json
- composer.lock
- config/handoff.php
- config/knowledge.php
- config/services.php
- database/migrations/2026_10_08_000003_drop_global_unique_analytics_daily_date.php
- database/migrations/2026_10_08_000004_encrypt_webhook_secrets.php
- database/migrations/2026_10_10_000009_create_handoff_tasks_table.php
- database/migrations/2026_10_10_000010_add_ai_setup_tested_at_to_business_profiles.php
- database/migrations/2026_10_10_000011_add_due_at_to_handoff_tasks.php
- database/migrations/2026_10_10_000012_add_handoff_task_notification_type.php
- database/migrations/2026_10_10_000018_add_content_hash_to_business_knowledge_files.php
- database/migrations/2026_10_10_000020_add_media_processing_disclosure_to_business_profiles.php
- database/migrations/2026_10_10_000021_add_estimated_time_savings_inputs_to_business_profiles.php
- database/migrations/2026_10_10_000022_add_business_profile_details.php
- database/migrations/2026_10_10_000023_create_ai_reply_audits_table.php
- database/migrations/2026_10_10_000026_add_business_data_retention_and_rights_requests.php
- database/migrations/2026_10_10_000027_add_ai_go_live_requirements.php
- database/migrations/2026_10_10_000028_add_handoff_resolution_notes.php
- database/migrations/2026_10_10_000030_add_currency_to_business_profiles.php
- database/migrations/2026_10_10_000033_hash_api_key_secrets.php
- routes/api-n8n.php
- routes/api.php
- routes/console.php

### Backend f2db9b3 — billing: scope payments and add optional renewal
- app/Http/Controllers/Api/BillingController.php
- app/Http/Controllers/Api/MyFatoorahController.php
- app/Http/Controllers/Api/MyFatoorahWebhookController.php
- app/Http/Controllers/Api/PaymentController.php
- app/Http/Controllers/Api/SubscriptionController.php
- app/Models/PaymentIntent.php
- app/Models/Subscription.php
- app/Services/BillingService.php
- app/Services/MyFatoorahService.php
- app/Services/PaymentIntentAmountVerifier.php
- app/Services/PaymobService.php
- app/Services/UsageMeter.php
- database/migrations/2026_10_10_000024_scope_subscriptions_to_business_and_add_outbound_limit.php
- database/migrations/2026_10_10_000035_add_expected_payment_amount_to_intents.php
- database/migrations/2026_10_11_000003_add_subscription_auto_renewal.php
- docs/migration-notes/phase-8-n3-business-billing-and-usage.md
- tests/Feature/BusinessBillingScopeTest.php
- tests/Feature/PaymentIntentAmountTest.php
- tests/Feature/SubscriptionAutoRenewTest.php

### Backend 945240a — auth: harden sign-in and business membership
- app/Http/Controllers/Api/AuthController.php
- app/Http/Controllers/Api/OnboardingController.php
- app/Http/Controllers/Api/SocialAuthController.php
- app/Http/Controllers/Api/TeamController.php
- app/Models/TeamInvitation.php
- app/Models/TeamMember.php
- app/Services/OAuthLoginExchange.php
- app/Services/OAuthStateService.php
- app/Services/OnboardingService.php
- config/sanctum.php
- database/migrations/2026_10_08_000005_add_manager_team_role.php
- database/migrations/2026_10_10_000001_create_team_invitations_table.php
- database/migrations/2026_10_10_000002_add_team_invitation_notification_type.php
- docs/migration-notes/phase-4-o4-agent-commerce-authorization.md
- docs/migration-notes/phase-8-n8-n10-regional-onboarding-privacy.md
- tests/Feature/ActiveBusinessResolutionTest.php
- tests/Feature/AuthHardeningTest.php
- tests/Feature/BotTenantAuthorizationTest.php
- tests/Feature/BusinessTenantAuthorizationTest.php
- tests/Feature/OAuthLoginExchangeTest.php
- tests/Feature/OnboardingRealityTest.php
- tests/Feature/OnboardingTenantSafetyTest.php
- tests/Feature/PublicTeamInvitationFlowTest.php
- tests/Feature/SallaOAuthTest.php
- tests/Feature/TeamBusinessMembershipLifecycleTest.php
- tests/Feature/TeamInvitationAcceptanceTest.php

### Backend bb9ecaf — flags: apply global safety rollout decisions
- app/Http/Controllers/Api/BusinessFeatureFlagController.php
- app/Models/BusinessFeatureFlagAssignment.php
- app/Models/FeatureFlag.php
- config/features.php
- database/migrations/2026_10_10_000031_add_business_feature_flag_rollouts.php
- database/migrations/2026_10_10_000032_add_message_safety_rollout_flags.php
- database/migrations/2026_10_11_000001_enable_message_safety_rollout_flags_globally.php
- tests/Feature/AiSetupVerificationTest.php
- tests/Feature/BusinessFeatureFlagTest.php

### Backend 27b0416 — inbox: add per-agent read state and handoffs
- app/Http/Controllers/InboxController.php
- app/Jobs/PollGmailInbox.php
- app/Support/InboxBroadcastPayload.php
- database/migrations/2026_10_11_000002_create_conversation_reads_table.php
- docs/migration-notes/phase-8-n6-handoff-sla-inbox.md
- tests/Feature/HandoffTaskFlowTest.php
- tests/Feature/InboxRealtimeTest.php

### Backend 727f8c0 — media: secure private media processing
- app/Console/Commands/ExpireInboundMedia.php
- app/Http/Controllers/Api/MediaProcessingDisclosureController.php
- app/Http/Controllers/Api/MultimodalController.php
- app/Http/Controllers/PrivateMediaController.php
- app/Services/ImageUnderstandingService.php
- app/Services/IncomingMediaStorage.php
- app/Services/VoiceService.php
- config/filesystems.php
- tests/Feature/ImageUnderstandingServiceTest.php
- tests/Feature/IncomingMediaStorageTest.php
- tests/Feature/MediaProcessingDisclosureTest.php
- tests/Feature/MultimodalImageAnalysisTest.php
- tests/Feature/SallaImageSendTest.php
- tests/Feature/VoiceServiceTest.php

### Backend eee930f — messaging: harden consent and delivery workflows
- .github/workflows/frontend-api-contract.yml
- app/Console/Commands/CheckNoReplySequences.php
- app/Console/Commands/ResyncTelegramWebhooks.php
- app/Console/Commands/RunDueTimeWorkflows.php
- app/Console/Commands/SendDueCampaigns.php
- app/Console/Commands/SendDueEmailCampaigns.php
- app/Events/ConversationUpdated.php
- app/Events/MessageReceived.php
- app/Exceptions/AutomationPausedException.php
- app/Http/Controllers/Api/AutomationController.php
- app/Http/Controllers/Api/AutomationRunControlController.php
- app/Http/Controllers/Api/BotController.php
- app/Http/Controllers/Api/CampaignAudienceController.php
- app/Http/Controllers/Api/CampaignController.php
- app/Http/Controllers/Api/EmailCampaignController.php
- app/Http/Controllers/Api/ProactiveController.php
- app/Http/Controllers/Api/SallaWebhookController.php
- app/Http/Controllers/Api/SequenceController.php
- app/Http/Controllers/Api/TrainingController.php
- app/Http/Controllers/Api/WhatsAppCloudController.php
- app/Http/Controllers/Api/WhatsAppController.php
- app/Http/Controllers/ChannelController.php
- app/Http/Controllers/TelegramController.php
- app/Http/Controllers/WooCommerceController.php
- app/Jobs/CheckNoReplyForMessage.php
- app/Jobs/ExecuteSequenceStep.php
- app/Jobs/ProcessAutoReply.php
- app/Jobs/ProcessAutoReplyWithRetry.php
- app/Jobs/RetryFailedMessage.php
- app/Jobs/SallaWebhookJob.php
- app/Jobs/SendCampaignMessage.php
- app/Jobs/SendEmailCampaignMessage.php
- app/Jobs/SendProactiveMessage.php
- app/Jobs/SyncCommerceStore.php
- app/Jobs/SyncSallaCustomers.php
- app/Jobs/SyncSallaOrders.php
- app/Mail/EmailCampaignMail.php
- app/Mail/ScheduledTaskFailureMail.php
- app/Models/Bot.php
- app/Models/Campaign.php
- app/Models/CampaignLog.php
- app/Models/Channel.php
- app/Models/CommerceOrder.php
- app/Models/CommerceOrderAttempt.php
- app/Models/ContactConsent.php
- app/Models/Conversation.php
- app/Models/EmailCampaign.php
- app/Models/EmailCampaignRecipient.php
- app/Models/Message.php
- app/Models/ProactiveCampaign.php
- app/Models/SequenceEnrollment.php
- app/Models/SequenceStepExecution.php
- app/Models/WhatsAppCloudTemplate.php
- app/Models/WhatsAppInstance.php
- app/Models/WorkflowExecution.php
- app/Services/AutomationEngine.php
- app/Services/AutomationRunControl.php
- app/Services/CampaignAudiencePreviewService.php
- app/Services/CampaignLaunchService.php
- app/Services/ChannelMessageService.php
- app/Services/ChannelStabilityService.php
- app/Services/CommerceStoreSyncService.php
- app/Services/ConsentDecision.php
- app/Services/ConsentGate.php
- app/Services/EcommerceChannelResolver.php
- app/Services/EmailCampaignAudienceService.php
- app/Services/InboundConsentKeywordHandler.php
- app/Services/OrderCheckoutService.php
- app/Services/ProductAwarenessService.php
- app/Services/SallaService.php
- app/Services/SequenceEnrollmentService.php
- app/Services/SequenceExecutionService.php
- app/Services/SequenceService.php
- app/Services/SequenceTriggerService.php
- app/Services/TelegramService.php
- app/Services/TrainingStatsService.php
- app/Services/WhatsAppCloudApiService.php
- app/Services/WooCommerceService.php
- config/consent.php
- database/migrations/2026_10_08_000001_add_tracking_links_to_email_campaign_recipients.php
- database/migrations/2026_10_08_000002_create_sequence_no_reply_cycles_table.php
- database/migrations/2026_10_10_000003_create_contact_consent_and_suppression_tables.php
- database/migrations/2026_10_10_000004_create_whatsapp_cloud_templates_table.php
- database/migrations/2026_10_10_000005_add_whatsapp_cloud_template_to_campaigns.php
- database/migrations/2026_10_10_000006_expand_campaign_log_status_for_claims.php
- database/migrations/2026_10_10_000007_add_partially_sent_campaign_status.php
- database/migrations/2026_10_10_000008_add_provider_message_id_to_campaign_logs.php
- database/migrations/2026_10_10_000013_add_ecommerce_connection_to_bot_channels.php
- database/migrations/2026_10_10_000014_create_commerce_order_attempts_table.php
- database/migrations/2026_10_10_000015_add_execution_indexes_to_sequence_steps.php
- database/migrations/2026_10_10_000016_add_paused_status_to_sequence_enrollments.php
- database/migrations/2026_10_10_000017_add_workflow_event_idempotency.php
- database/migrations/2026_10_10_000019_add_media_processing_status_to_messages.php
- database/migrations/2026_10_10_000025_scope_legacy_whatsapp_instances_to_businesses.php
- database/migrations/2026_10_10_000029_add_automation_run_controls.php
- database/migrations/2026_10_10_000034_backfill_legacy_verified_emails.php
- docs/migration-notes/2026-10-campaign-launch-service.md
- docs/migration-notes/phase-3-r7-agent-channel-commerce-routing.md
- docs/migration-notes/phase-4-o7-provider-backed-message-status.md
- docs/migration-notes/phase-5-a1-sequence-execution-recovery.md
- docs/migration-notes/phase-5-a2-sequence-channel-isolation.md
- docs/migration-notes/phase-5-a3-sequence-consent-and-automation-safety.md
- docs/migration-notes/phase-5-a4-workflow-runtime.md
- docs/migration-notes/phase-8-n2-message-delivery-status.md
- docs/migration-notes/phase-8-n7-automation-run-controls.md
- resources/views/emails/scheduled-task-failure.blade.php
- tests/Feature/AIPromptContextTest.php
- tests/Feature/AuditSecurityAndSequenceTriggersTest.php
- tests/Feature/BotCommerceConnectionsApiTest.php
- tests/Feature/BotKnowledgeRetrievalTest.php
- tests/Feature/CampaignConsentGateTest.php
- tests/Feature/CheckoutOrderFlowTest.php
- tests/Feature/CommerceConnectionFlowTest.php
- tests/Feature/CommerceContextResolverTest.php
- tests/Feature/ContactConsentGateTest.php
- tests/Feature/CustomerResolutionTest.php
- tests/Feature/EmailCampaignConsentGateTest.php
- tests/Feature/EmailUnsubscribeTest.php
- tests/Feature/ProactiveConsentGateTest.php
- tests/Feature/ProductReplyContextTest.php
- tests/Feature/SallaAggregateQueryTest.php
- tests/Feature/SallaOrderStateRecoveryTest.php
- tests/Feature/SallaProductBrowseIntentTest.php
- tests/Feature/SequenceEndToEndTest.php
- tests/Feature/SequenceEnrollmentServiceTest.php
- tests/Feature/SequenceExecutionTest.php
- tests/Feature/SequenceHardeningTest.php
- tests/Feature/SequenceServiceTest.php
- tests/Feature/TelegramMessageSendTest.php
- tests/Feature/TelegramWebhookRoutingTest.php
- tests/Feature/WhatsAppCampaignPolicyTest.php
- tests/Feature/WhatsAppCloudApiTest.php
- tests/Feature/WhatsAppInstanceIdTest.php
- tests/Feature/WorkflowEngineRefactorTest.php
- tests/Feature/WorkflowSequenceE2ETest.php
- tests/Feature/WorkflowTest.php
- tests/Unit/SallaServiceTest.php

### Backend d5b0b4d — tests: document remediation and add regression coverage
- docs/metrics/metric-dictionary.md
- docs/migration-notes/2026-10-agent-routing-handoff.md
- docs/migration-notes/REMEDIATION-PHASE-3-STATUS.md
- docs/migration-notes/phase-3-r3-ai-setup-verification.md
- docs/migration-notes/phase-3-r4-handoff-tasks.md
- docs/migration-notes/phase-3-r5-agent-escalation-settings.md
- docs/migration-notes/phase-3-r6-atomic-agent-setup.md
- docs/migration-notes/phase-3-r8-inherit-business-ai-provider.md
- docs/migration-notes/phase-4-o3-store-scoped-product-actions.md
- docs/migration-notes/phase-4-o5-durable-order-attempts.md
- docs/migration-notes/phase-4-o6-explicit-order-contact-data.md
- docs/migration-notes/phase-4-o8-checkout-summary-cancellation.md
- docs/migration-notes/phase-5-a5-knowledge-indexing.md
- docs/migration-notes/phase-5-a6-private-media-ingestion.md
- docs/migration-notes/phase-7-f1-reporting-ux-and-f2-f3.md
- docs/migration-notes/phase-8-n1-ai-reply-audit.md
- docs/migration-notes/phase-8-n4-data-rights-and-retention.md
- docs/migration-notes/phase-8-n5-ai-go-live-checklist.md
- docs/migration-notes/phase-9-api-key-hashing.md
- docs/migration-notes/phase-9-scheduler-operations.md
- docs/operations/backup-and-retention.md
- docs/operations/behavior-rollout-flags.md
- scripts/check-frontend-api-routes.mjs
- tests/Feature/AiReplyAuditAccessTest.php
- tests/Feature/AuditContinuationBackendTest.php
- tests/Feature/AutomationRunControlTest.php
- tests/Feature/BackupDatabaseTest.php
- tests/Feature/BusinessDataRightsTest.php
- tests/Feature/BusinessUsageMeterTest.php
- tests/Feature/ChannelsEndpointTest.php
- tests/Feature/ConditionEvaluationServiceTest.php
- tests/Feature/DailyAnalyticsTimezoneTest.php
- tests/Feature/DashboardDateRangeTest.php
- tests/Feature/FrontendContractEndpointsTest.php
- tests/Feature/MetaWebhookSignatureTest.php
- tests/Feature/N8nRoutesSecurityTest.php
- tests/Feature/OperationalMonitoringTest.php
- tests/Feature/OrdersAndBusinessProfileApiTest.php
- tests/Feature/OutboundMessageStatusTest.php
- tests/Feature/ProcessKnowledgeFileTest.php
- tests/Feature/PublicApiMessageDeliveryTest.php
- tests/Feature/RegionalBusinessDefaultsTest.php
- tests/Feature/ReportsMetricDefinitionsTest.php
- tests/Feature/TrainingStatsEndpointTest.php
- tests/Unit/AICapabilitiesRateLimitTest.php
- tests/Unit/SensitiveLogContextTest.php

### Frontend fe7be8a — security: clarify protected dashboard actions
- app/dashboard/api-keys/ApiKeysContent.tsx
- app/dashboard/privacy-data/page.tsx
- app/privacy/page.tsx
- components/dashboard/DashboardLayout.tsx
- lib/businessContext.ts

### Frontend 4d7135b — billing: add explicit monthly renewal choice
- app/checkout/page.tsx
- app/dashboard/billing/page.tsx
- components/BillingDashboard.tsx

### Frontend 803ef0b — auth: improve account and team flows
- app/accept-invitation/page.tsx
- app/auth/callback/page.tsx
- app/dashboard/team/page.tsx
- app/setup-account/page.tsx
- app/team/invitations/accept/page.tsx
- components/auth/LoginForm.tsx
- components/auth/OnboardingWizard.tsx
- lib/AuthContext.tsx

### Frontend 6d2666e — inbox: support per-agent read and handoff workflows
- app/dashboard/inbox/page.tsx
- components/inbox/HandoffTaskQueue.tsx
- components/inbox/MessageBubble.tsx
- hooks/useInbox.ts

### Frontend 19fe9dc — media: update image analysis workspace
- app/dashboard/multimodal/MultimodalContent.tsx

### Frontend f935dc0 — messaging: complete channel and automation workflows
- app/dashboard/ai-knowledge/AIKnowledgeContent.tsx
- app/dashboard/analytics/page.tsx
- app/dashboard/automation-controls/page.tsx
- app/dashboard/automations/builder/page.tsx
- app/dashboard/bots/page.tsx
- app/dashboard/campaigns/CampaignsContent.tsx
- app/dashboard/channels/page.tsx
- app/dashboard/classification/ClassificationContent.tsx
- app/dashboard/handoffs/page.tsx
- app/dashboard/order-notifications/OrderNotificationsContent.tsx
- app/dashboard/orders/page.tsx
- app/dashboard/page.tsx
- app/dashboard/reports/page.tsx
- app/dashboard/sequences/[id]/page.tsx
- app/dashboard/sequences/new/page.tsx
- app/dashboard/settings/page.tsx
- app/dashboard/training/page.tsx
- app/dashboard/training/review/page.tsx
- app/dashboard/whatsapp/page.tsx
- app/dashboard/workflows/WorkflowContent.tsx
- app/terms/page.tsx
- components/AnalyticsDashboard.tsx
- components/DripSequencesBuilder.tsx
- components/NotificationCenter.tsx
- components/automations/WorkflowBuilder.tsx
- components/bots/BotWizardModal.tsx
- components/campaigns/SegmentsAndTemplates.tsx
- components/channels/ChannelCard.tsx
- components/channels/WhatsAppConnect.tsx
- components/dashboard/TrainingReview.tsx
- components/ui/SocialLoginButtons.tsx
- hooks/useSequences.ts

### Frontend 4010247 — tests: define frontend line endings and CI contract
- .gitattributes
- .github/workflows/backend-api-contract.yml
