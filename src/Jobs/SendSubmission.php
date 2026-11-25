<?php

namespace Stoffelio\BambooConnector\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Statamic\Contracts\Forms\Submission;
use Stoffelio\BambooConnector\Submissions\Sender;
use Throwable;

/**
 * Posts one submission to the CRM.
 *
 * Queueing it is what keeps the uploads alive: Statamic counts a connection job towards a
 * submission's temporary files only when it can push its own middleware onto the job, so a
 * job that is not queued is treated as finished the moment it is dispatched and the files
 * can be deleted while the request is still in flight.
 *
 * Retrying is safe because the CRM treats `external_id` as idempotent: a repeat answers
 * 200 with the original ticket id rather than opening a second one.
 */
class SendSubmission implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;

    public function __construct(
        public Submission $submission,
        public array $config = [],
    ) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 30, 120, 600];
    }

    public function handle(Sender $sender): void
    {
        $submission = $this->submission->form()->submission($this->submission->id()) ?? $this->submission;

        $response = $sender->send($submission);

        // A refused second step is final: the step was already completed, or the ticket is
        // not one the CRM will take. Retrying would only repeat the same answer.
        if ($response->status() === 422 && $state = $response->json('state')) {
            Log::warning('Bamboo: the second step of submission '.$submission->id().' was refused as '.$state.'.');

            return;
        }

        $response->throw();

        if ($response->json('mapping_failed')) {
            Log::warning('Bamboo: submission '.$submission->id().' became ticket '.$response->json('ticket_id').' but could not be mapped.');
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Bamboo: submission '.$this->submission->id().' from '.$this->submission->form()->handle().' was not delivered.', [
            'exception' => $exception->getMessage(),
        ]);
    }
}
