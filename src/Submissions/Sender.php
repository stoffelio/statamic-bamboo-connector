<?php

namespace Stoffelio\BambooConnector\Submissions;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Statamic\Contracts\Forms\Submission;

/**
 * The one place a submission is actually posted.
 *
 * Shared because the same send happens two ways: queued for an ordinary submission, and
 * in-request for the first step of a two-step form, which cannot be queued because the
 * page is waiting on the ticket id and the continuation token that come back with it.
 */
class Sender
{
    public function send(Submission $submission, ?int $timeout = null): Response
    {
        $attachments = new Attachments($submission);
        $files = $attachments->prepare();

        try {
            return $this->post(new SubmissionPayload($submission, $files), $files, $timeout);
        } finally {
            $attachments->cleanup();
        }
    }

    public static function isConfigured(): bool
    {
        return filled(config('bamboo.url')) && filled(config('bamboo.token'));
    }

    /** @param  array<int, Attachment>  $files */
    private function post(SubmissionPayload $payload, array $files, ?int $timeout): Response
    {
        $request = Http::acceptJson()
            ->withToken(config('bamboo.token'))
            ->connectTimeout($timeout ?? 5)
            ->timeout($timeout ?? (int) config('bamboo.timeout', 20));

        foreach ($files as $file) {
            $request = $request->attach(
                "files[{$file->handle}][]",
                fopen($file->path, 'r'),
                $file->filename,
                ['Content-Type' => $file->mimeType],
            );
        }

        return $files
            ? $request->post($payload->endpoint(), $payload->toMultipart())
            : $request->asJson()->post($payload->endpoint(), $payload->toArray());
    }
}
