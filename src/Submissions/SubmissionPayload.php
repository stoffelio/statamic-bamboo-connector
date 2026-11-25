<?php

namespace Stoffelio\BambooConnector\Submissions;

use Statamic\Contracts\Forms\Submission;

/**
 * The body of a submission post, in both shapes it has to travel in, and the endpoint it
 * is going to.
 *
 * JSON keeps the types the CRM maps on — a boolean stays a boolean, a checkbox list stays
 * a list — so it is used whenever a submission carries no files. Files force multipart,
 * where everything is a string and nested keys have to be spelled out in brackets, and
 * `fields[0][value][]` is what the CRM's `fields.*` rules read.
 *
 * Which endpoint depends on the submission itself: one carrying a continuation token is
 * the second step of a form whose first step already made a ticket, so it is appended to
 * that ticket rather than opening another.
 */
class SubmissionPayload
{
    /** A key on the form, not a field: see expectsFollowUp(). */
    public const FOLLOW_UP = 'bamboo_follow_up';

    public const TICKET = 'bamboo_ticket';

    public const CONTINUATION = 'bamboo_continuation';

    /** @param  array<int, Attachment>  $attachments */
    public function __construct(
        private Submission $submission,
        private array $attachments = [],
    ) {}

    public function endpoint(): string
    {
        $base = rtrim((string) config('bamboo.url'), '/').'/api/submissions';

        return $this->isSecondStep()
            ? $base.'/'.$this->submission->get(self::TICKET).'/continue'
            : $base;
    }

    public function isSecondStep(): bool
    {
        return filled($this->submission->get(self::CONTINUATION)) && filled($this->submission->get(self::TICKET));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $payload = [
            'external_id' => $this->submission->id(),
            'form' => [
                'handle' => $this->submission->form()->handle(),
                'title' => $this->submission->form()->title(),
            ],
            'fields' => SubmissionPresenter::for($this->submission, $this->attachments)->fields(),
        ];

        if ($this->isSecondStep()) {
            return [...$payload, 'continuation_token' => $this->submission->get(self::CONTINUATION)];
        }

        return [...$payload, 'expects_follow_up' => $this->expectsFollowUp()];
    }

    /**
     * A first step says so, so the CRM issues a continuation token and flags the ticket as
     * waiting for the rest rather than letting it look complete.
     *
     * It reads `bamboo_follow_up: true` on the form rather than a hidden field, because
     * what makes a form a first step was decided when it was built. A hidden field would
     * let whoever is filling it in decide instead.
     */
    public function expectsFollowUp(): bool
    {
        return (bool) $this->submission->form()->get(self::FOLLOW_UP);
    }

    /** @return array<string, string> */
    public function toMultipart(): array
    {
        $flat = [];

        $walk = function (mixed $value, string $key) use (&$walk, &$flat): void {
            if (is_array($value)) {
                foreach ($value as $childKey => $childValue) {
                    $walk($childValue, "{$key}[{$childKey}]");
                }

                return;
            }

            if ($value === null) {
                return;
            }

            $flat[$key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        };

        foreach ($this->toArray() as $key => $value) {
            $walk($value, $key);
        }

        return $flat;
    }
}
