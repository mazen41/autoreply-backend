<?php

namespace App\Console\Commands;

use App\Models\Channel;
use Illuminate\Console\Command;

/**
 * Clears the Facebook page ID that an earlier callback copied onto Instagram rows.
 *
 * Dry run by default. Pass --apply to write. Only rows whose page_id equals a
 * connected Facebook page owned by the same user are changed; the value is set
 * to null. Rows with no instagram_account_id cannot be routed and are reported
 * so the merchant can reconnect them.
 */
class RepairInstagramPageIds extends Command
{
    protected $signature = 'channels:repair-instagram-page-ids {--apply : Write changes (default is a dry run)}';

    protected $description = 'Clear Facebook page IDs stored on Instagram channel rows';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $fixable = [];
        $unroutable = [];

        Channel::where('type', 'instagram')->orderBy('id')->each(function (Channel $ig) use (&$fixable, &$unroutable) {
            if (empty($ig->instagram_account_id)) {
                $unroutable[] = [$ig->id, $ig->user_id, $ig->page_id ?? '-', 'no instagram_account_id'];
                return;
            }
            if (empty($ig->page_id)) {
                return;
            }

            $sameUserPage = Channel::where('type', 'facebook')
                ->where('user_id', $ig->user_id)
                ->where('page_id', $ig->page_id)
                ->exists();

            if ($sameUserPage) {
                $fixable[] = [$ig->id, $ig->user_id, $ig->page_id, $ig->instagram_account_id];
            }
        });

        $this->info($apply ? 'APPLY MODE' : 'DRY RUN (no changes written; pass --apply to write)');

        if ($fixable !== []) {
            $this->table(['instagram_channel_id', 'user_id', 'copied_page_id', 'instagram_account_id'], $fixable);
        } else {
            $this->line('No Instagram rows carry a copied Facebook page ID.');
        }

        if ($unroutable !== []) {
            $this->warn('Instagram rows that cannot be routed until reconnected:');
            $this->table(['instagram_channel_id', 'user_id', 'page_id', 'reason'], $unroutable);
        }

        if ($apply) {
            foreach ($fixable as [$id]) {
                Channel::where('id', $id)->update(['page_id' => null]);
            }
            $this->info('Cleared page_id on ' . count($fixable) . ' Instagram row(s).');
        }

        return self::SUCCESS;
    }
}