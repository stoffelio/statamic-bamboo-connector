<?php

namespace Stoffelio\BambooConnector\Submissions;

use Statamic\Contracts\Forms\Submission;

/**
 * The body of a submission post, in both shapes it has to travel in.
 *
 * JSON keeps the types the CRM maps on — a boolean stays a boolean, a checkbox list stays
 * a list — so it is used whenever a submission carries no files. Files force multipart,
 * where everything is a string and nested keys have to be spelled out in brackets, and
 * `fields[0][value][]` is what the CRM's `fields.*` rules read.
 */
class SubmissionPayload
{
    /** @param  array<int, Attachment>  $attachments */
    public function __construct(
        private Submission $submission,
        private bool $expectsFollowUp = false,
        private array $attachments = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'external_id' => $this->submission->id(),
            'form' => [
                'handle' => $this->submission->form()->handle(),
                'title' => $this->submission->form()->title(),
            ],
            'expects_follow_up' => $this->expectsFollowUp,
            'fields' => SubmissionPresenter::for($this->submission, $this->attachments)->fields(),
        ];
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
