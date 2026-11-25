<?php

namespace Stoffelio\BambooConnector\Submissions;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Statamic\Contracts\Assets\Asset;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Contracts\Forms\Submission;
use Statamic\Contracts\Taxonomies\Term;
use Statamic\Facades\Entry;
use Statamic\Fields\Field;
use Statamic\Fields\Value;
use Statamic\Support\Arr;
use Throwable;

/**
 * Reads a submission into the ordered rows the CRM ingests.
 *
 * Every row carries both a `value`, which the CRM maps onto its own vocabulary, and a
 * `display`, which it prints in a comment when it recognises nothing — so a date has to
 * arrive as 2026-10-14 and "14 October 2026" at once. Resolving the second half is the
 * whole job: a dictionary, a select and an entry each augment to a different shape, and
 * only the blueprint knows which order a human should read the rows in.
 */
class SubmissionPresenter
{
    private const DISPLAY_ONLY = ['form_heading', 'html', 'spacer', 'info', 'section', 'revealer'];

    private const VERIFICATION = ['turnstile', 'captcha', 'recaptcha', 'honeypot'];

    private const UPLOADS = ['form_upload', 'files', 'assets'];

    /** @param  array<int, Attachment>  $attachments  The files that are actually going up. */
    public function __construct(private Submission $submission, private array $attachments = []) {}

    /** @param  array<int, Attachment>  $attachments */
    public static function for(Submission $submission, array $attachments = []): self
    {
        return new self($submission, $attachments);
    }

    /** @return array<int, array<string, mixed>> */
    public function fields(): array
    {
        $data = $this->submission->data();

        return $this->fieldsToSend()
            ->map(fn (Field $field) => $this->present($field, $data->get($field->handle())))
            ->values()
            ->all();
    }

    /** @return Collection<string, Field> */
    public function uploadFields(): Collection
    {
        return $this->fieldsToSend()->filter(fn (Field $field) => $this->isUpload($field));
    }

    /** @return Collection<string, Field> */
    private function fieldsToSend(): Collection
    {
        $honeypot = $this->submission->form()->honeypot();

        return $this->submission->form()->blueprint()->fields()->all()
            ->reject(fn (Field $field) => $this->isNoise($field, $honeypot));
    }

    /**
     * A field nobody should read in a comment row: the spam defences, the fields that only
     * lay out the form, and the confirmation inputs that repeat an answer back.
     */
    private function isNoise(Field $field, ?string $honeypot): bool
    {
        if ($field->handle() === $honeypot) {
            return true;
        }

        if (str_starts_with($field->handle(), 'confirm_')) {
            return true;
        }

        if (in_array($field->type(), [...self::DISPLAY_ONLY, ...self::VERIFICATION], true)) {
            return true;
        }

        return $field->formField()?->fieldtype()->collectsValue() === false;
    }

    /** @return array<string, mixed> */
    private function present(Field $field, mixed $raw): array
    {
        // Named from the prepared attachments rather than from what the form stored, so a
        // file the CRM would have refused is not announced as if it had been sent.
        if ($this->isUpload($field)) {
            $names = collect($this->attachments)
                ->filter(fn (Attachment $attachment) => $attachment->handle === $field->handle())
                ->map(fn (Attachment $attachment) => $attachment->filename)
                ->values();

            return $this->row($field, 'file', $names->all(), $names->implode(', '));
        }

        [$value, $display] = $this->resolve($field, $raw);

        return $this->row($field, $field->type(), $value, $display);
    }

    /** @return array<string, mixed> */
    private function row(Field $field, string $type, mixed $value, string $display): array
    {
        return [
            'handle' => $field->handle(),
            'label' => $field->display() ?? $field->handle(),
            'type' => $type,
            'value' => $value,
            'display' => $display,
        ];
    }

    /** @return array{0: mixed, 1: string} */
    private function resolve(Field $field, mixed $raw): array
    {
        if ($this->isDate($field)) {
            return $this->date($raw);
        }

        $augmented = $this->augment($field, $raw);

        if ($field->handle() === config('bamboo.organization_field')) {
            return $this->organization($raw, $augmented);
        }

        return $this->flatten($augmented);
    }

    private function augment(Field $field, mixed $raw): mixed
    {
        $value = (clone $field)->setValue($raw)->augment()->value();

        return $value instanceof Value ? $value->value() : $value;
    }

    /**
     * The CRM builds an appointment out of the value later, so it has to be an ISO date
     * whatever the field was: Forms 2 has a date fieldtype, the forms this replaces used a
     * text field with a date input.
     *
     * @return array{0: mixed, 1: string}
     */
    private function date(mixed $raw): array
    {
        $raw = is_array($raw) ? Arr::get($raw, 'date', Arr::first($raw)) : $raw;

        if (blank($raw)) {
            return ['', ''];
        }

        try {
            $date = Carbon::parse($raw);
        } catch (Throwable) {
            return [(string) $raw, (string) $raw];
        }

        return [$date->toDateString(), $date->translatedFormat(config('bamboo.date_format'))];
    }

    /**
     * The CRM takes either one of its own organization ids or a name it can match, so a
     * picker holding an entry is worth resolving here — the entry knows its CRM id and the
     * CRM has no idea what a Statamic id is.
     *
     * @return array{0: mixed, 1: string}
     */
    private function organization(mixed $raw, mixed $augmented): array
    {
        [$value, $display] = $this->flatten($augmented);

        $entry = $this->entry($raw) ?? $this->entry($value);

        if ($entry && filled($id = $entry->get(config('bamboo.organization_id_field')))) {
            return [(string) $id, (string) $entry->get('title')];
        }

        return [$value, $display];
    }

    private function entry(mixed $id): ?EntryContract
    {
        if (! is_string($id) && ! is_int($id)) {
            return null;
        }

        return blank($id) ? null : Entry::find((string) $id);
    }

    /** @return array{0: mixed, 1: string} */
    private function flatten(mixed $value): array
    {
        if ($value instanceof Value) {
            return $this->flatten($value->value());
        }

        if (blank($value) && ! is_bool($value) && $value !== 0 && $value !== '0') {
            return ['', ''];
        }

        if (is_bool($value)) {
            return [$value, $value ? 'Yes' : 'No'];
        }

        if ($value instanceof EntryContract || $value instanceof Term) {
            return [$value->id(), (string) $value->get('title')];
        }

        if ($value instanceof Asset) {
            return [$value->path(), $value->basename()];
        }

        if ($value instanceof Collection) {
            return $this->flatten($value->all());
        }

        if (is_array($value)) {
            return array_is_list($value) ? $this->flattenList($value) : $this->flattenItem($value);
        }

        // A select augments to a LabeledValue and a dictionary to an Item. Both know the
        // option a person picked, which casting either to a string would throw away.
        if ($value instanceof Arrayable) {
            return $this->flatten($value->toArray());
        }

        if (is_object($value)) {
            return method_exists($value, '__toString')
                ? [(string) $value, (string) $value]
                : ['', ''];
        }

        return [$value, (string) $value];
    }

    /**
     * A select or a dictionary augments to the option itself, so the key the form stored
     * and the label a person picked are both already there.
     *
     * @param  array<string, mixed>  $item
     * @return array{0: mixed, 1: string}
     */
    private function flattenItem(array $item): array
    {
        $value = $item['value'] ?? $item['key'] ?? $item['id'] ?? null;
        $display = $item['label'] ?? $item['name'] ?? $item['title'] ?? $value;

        if ($value === null) {
            return [$item, implode(', ', array_map(fn ($part) => (string) $part, array_filter($item, 'is_scalar')))];
        }

        return [$value, (string) $display];
    }

    /**
     * @param  array<int, mixed>  $list
     * @return array{0: mixed, 1: string}
     */
    private function flattenList(array $list): array
    {
        $resolved = collect($list)->map(fn ($item) => $this->flatten($item));

        return [
            $resolved->map(fn (array $pair) => $pair[0])->all(),
            $resolved->map(fn (array $pair) => $pair[1])->filter(fn (string $display) => $display !== '')->implode(', '),
        ];
    }

    private function isDate(Field $field): bool
    {
        return $field->type() === 'date' || $field->get('input_type') === 'date';
    }

    private function isUpload(Field $field): bool
    {
        return in_array($field->type(), self::UPLOADS, true);
    }
}
