<?php

namespace Stoffelio\BambooConnector\Listeners;

use Illuminate\Support\Facades\Log;
use Statamic\Contracts\Forms\Submission;
use Statamic\Events\SubmissionFinalized;
use Statamic\Fields\Field;
use Stoffelio\BambooConnector\BambooConnector;
use Stoffelio\BambooConnector\Submissions\Sender;
use Stoffelio\BambooConnector\Submissions\SubmissionPayload;
use Stoffelio\BambooConnector\Submissions\SubmissionPresenter;
use Throwable;

/**
 * Sends the first step of a two-step form while the visitor is still waiting.
 *
 * It happens here rather than in the connection job because the page needs the ticket id
 * and the follow-up link that come back with the answer, and a queued job cannot put
 * anything into a response that has already been sent.
 *
 * The timeout is short and every failure is swallowed on purpose: a CRM that is down must
 * not turn the best-converting form on the site into a spinner. The lead is captured
 * either way — the connection job still runs when this does not — and only the offer of a
 * second step is lost, which the CRM's autoreply can invite the patient back to.
 */
class SendFirstStep
{
    public function __construct(private Sender $sender) {}

    public function handle(SubmissionFinalized $event): void
    {
        $submission = $event->submission;

        if (! $this->shouldSend($submission)) {
            return;
        }

        try {
            $response = $this->sender->send($submission, timeout: (int) config('bamboo.first_step_timeout', 4));
        } catch (Throwable $exception) {
            Log::warning('Bamboo: the first step of submission '.$submission->id().' was not answered in time, so no second step is offered.', [
                'exception' => $exception->getMessage(),
            ]);

            return;
        }

        if (! $response->successful()) {
            return;
        }

        // Set after the submission was saved, so these ride back in the response without
        // being written to disk. They must not start with an underscore: Statamic keeps
        // those keys internal and strips them from the response, which is the one place
        // they are any use.
        $submission->set(SubmissionPayload::TICKET, $response->json('ticket_id'));

        // Where the rest of it is asked for. The CRM issues that page itself and puts the
        // same link in the automatic reply, so a visitor who closes the tab can finish from
        // their inbox instead.
        if (filled($url = $response->json('data_request_url'))) {
            $submission->set(SubmissionPayload::FOLLOW_UP_URL, $url);
        }
    }

    private function shouldSend(Submission $submission): bool
    {
        if (! $submission->form()->get(SubmissionPayload::FOLLOW_UP)) {
            return false;
        }

        if (! Sender::isConfigured() || ! $this->isConnected($submission)) {
            return false;
        }

        // An upload has to go through the queue instead, because Statamic only waits for
        // the temporary files of a connection job it can count, and this is not one.
        return ! $this->hasFiles($submission);
    }

    private function isConnected(Submission $submission): bool
    {
        return collect($submission->form()->connections()->get(BambooConnector::handle(), []))
            ->contains(fn (array $connection) => ($connection['enabled'] ?? true) !== false);
    }

    private function hasFiles(Submission $submission): bool
    {
        return SubmissionPresenter::for($submission)
            ->uploadFields()
            ->contains(fn (Field $field) => filled($submission->get($field->handle())));
    }
}
