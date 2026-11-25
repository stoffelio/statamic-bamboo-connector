<?php

namespace Stoffelio\BambooConnector\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Statamic\Contracts\Forms\Submission;
use Stoffelio\BambooConnector\Submissions\Attachment;
use Stoffelio\BambooConnector\Submissions\Attachments;
use Stoffelio\BambooConnector\Submissions\SubmissionPayload;
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
        public bool $expectsFollowUp = false,
    ) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 30, 120, 600];
    }

    public function handle(): void
    {
        $submission = $this->submission->form()->submission($this->submission->id()) ?? $this->submission;

        $attachments = new Attachments($submission);
        $files = $attachments->prepare();

        try {
            $response = $this->send(new SubmissionPayload($submission, $this->expectsFollowUp, $files), $files);
        } finally {
            $attachments->cleanup();
        }

        $response->throw();

        $this->reportMappingFailure($submission, $response);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Bamboo: submission '.$this->submission->id().' from '.$this->submission->form()->handle().' was not delivered.', [
            'exception' => $exception->getMessage(),
        ]);
    }

    /** @param  array<int, Attachment>  $files */
    private function send(SubmissionPayload $payload, array $files): Response
    {
        $request = Http::acceptJson()
            ->withToken(config('bamboo.token'))
            ->connectTimeout(5)
            ->timeout((int) config('bamboo.timeout', 20));

        foreach ($files as $file) {
            $request = $request->attach(
                "files[{$file->handle}][]",
                fopen($file->path, 'r'),
                $file->filename,
                ['Content-Type' => $file->mimeType],
            );
        }

        return $files
            ? $request->post($this->endpoint(), $payload->toMultipart())
            : $request->asJson()->post($this->endpoint(), $payload->toArray());
    }

    private function endpoint(): string
    {
        return rtrim((string) config('bamboo.url'), '/').'/api/submissions';
    }

    /**
     * A ticket the CRM stored but could not map is a 201 — the submission is safe and the
     * team can see it, so retrying would only duplicate work. It still needs saying.
     */
    private function reportMappingFailure(Submission $submission, Response $response): void
    {
        if ($response->json('mapping_failed')) {
            Log::warning('Bamboo: submission '.$submission->id().' became ticket '.$response->json('ticket_id').' but could not be mapped.');
        }
    }
}
