<?php

namespace App\Console\Commands;

use App\Jobs\NotifyPaymentClient;
use App\Models\CentralPayment;
use App\Models\CentralPaymentEvent;
use App\Services\CentralPaymentProcessor;
use Illuminate\Console\Command;
use Throwable;

class ReconcileCentralPayments extends Command
{
    protected $signature = 'payments:reconcile-central {--limit=100}';
    protected $description = 'Reconcile pending central MyFatoorah invoices and retry unacknowledged site notifications.';

    public function handle(CentralPaymentProcessor $processor): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $pending = CentralPayment::query()
            ->whereNotNull('myfatoorah_invoice_id')
            ->whereIn('status', ['pending', 'creating', 'verification_pending'])
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>=', now()->subDays(7));
            })
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        foreach ($pending as $payment) {
            try {
                $processor->verifyInvoiceId($payment);
            } catch (Throwable) {
                $payment->update(['status' => 'verification_pending']);
            }
        }

        CentralPaymentEvent::query()
            ->whereIn('processing_status', ['received', 'retrying'])
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (CentralPaymentEvent $event): void {
                NotifyPaymentClient::dispatch($event->central_payment_id, $event->event_reference);
            });

        $this->info(sprintf('Checked %d pending payment(s); queued retry notifications.', $pending->count()));
        return self::SUCCESS;
    }
}
