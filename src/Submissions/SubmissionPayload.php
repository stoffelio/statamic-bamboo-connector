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
 */
class SubmissionPayload
{
    /** A key on the form, not a field: see expectsFollowUp(). */
    public const FOLLOW_UP = 'bamboo_follow_up';

    public const TICKET = 'bamboo_ticket';

    /** Where the CRM says the rest of this submission is asked for. */
    public const FOLLOW_UP_URL = 'bamboo_follow_up_url';

    /** @param  array<int, Attachment>  $attachments */
    public function __construct(
        private Submission $submission,
        private array $attachments = [],
    ) {}

    public function endpoint(): string
    {
        return rtrim((string) config('bamboo.url'), '/').'/api/submissions';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'external_id' => $this->submission->id(),
            'form' => [
                'handle' => $this->submission->form()->handle(),
                'title' => $this->submission->form()->title(),
            ],
            'fields' => SubmissionPresenter::for($this->submission, $this->attachments)->fields(),
            'expects_follow_up' => $this->expectsFollowUp(),
        ];
    }

    /**
     * A form that says so has the CRM issue a data request and send the patient to it, and
     * the ticket is flagged as waiting for the rest rather than looking complete.
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
