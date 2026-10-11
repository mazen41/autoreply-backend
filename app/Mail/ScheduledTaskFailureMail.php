<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ScheduledTaskFailureMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $taskName, public string $failedAt) {}

    public function build(): self
    {
        return $this->subject("NazBiz scheduled task failed: {$this->taskName}")
            ->text('emails.scheduled-task-failure');
    }
}
