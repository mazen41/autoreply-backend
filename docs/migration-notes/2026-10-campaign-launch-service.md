# Campaign launcher migration note

Scheduled campaigns now launch through `CampaignLaunchService` rather than invoking an HTTP controller from the scheduler. Campaign eligibility, tenant-owned channel validation, approved WhatsApp template checks, atomic claiming, recipient selection, and queued delivery behavior remain enforced. There is no HTTP route for scheduled launching; merchants continue to use the existing Launch Now and schedule controls.
