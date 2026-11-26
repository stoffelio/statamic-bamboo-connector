<?php

namespace Stoffelio\BambooConnector;

use Illuminate\Support\Facades\Log;
use Statamic\Contracts\Forms\Submission;
use Statamic\Facades\Blueprint;
use Statamic\Fields\Blueprint as FieldsBlueprint;
use Statamic\Forms\Connectors\Connector;
use Statamic\Support\VueComponent;
use Stoffelio\BambooConnector\Jobs\SendSubmission;
use Stoffelio\BambooConnector\Submissions\SubmissionPayload;

/**
 * Sends submissions to the Bamboo CRM.
 *
 * There is deliberately nothing to configure per form. The CRM owns the vocabulary and
 * maps the handles it recognises, turning everything else into comment rows, so a form
 * built on that vocabulary needs no mapping here — which is the whole point of the
 * arrangement, and why adding a field to a form is not a code change on either side.
 */
class BambooConnector extends Connector
{
    protected static $title = 'Bamboo CRM';

    protected $developer = 'Stoffel';

    protected $icon = 'media-ticket';

    public function description(): ?string
    {
        return 'Send submissions to the Bamboo CRM, where each one becomes a ticket.';
    }

    public function isConfigured(): bool
    {
        return filled(config('bamboo.url')) && filled(config('bamboo.token'));
    }

    public function blueprint(): FieldsBlueprint
    {
        return Blueprint::make()->setContents(['fields' => []]);
    }

    public function render(): VueComponent
    {
        return VueComponent::render('bamboo-connector', [
            ...$this->blueprintProps(),
            'configured' => $this->isConfigured(),
            'endpoint' => $this->isConfigured() ? rtrim((string) config('bamboo.url'), '/').'/api/submissions' : null,
        ]);
    }

    protected function job(Submission $submission, array $connection): ?object
    {
        // A form that asks for more afterwards is sent in the request that submitted it, so
        // the page can send the visitor on to the rest. Queueing it again would only ask
        // the CRM to recognise its own duplicate.
        //
        // The ticket id is the proof that it went: SendFirstStep writes it only once the
        // CRM has answered, so a send that timed out still leaves this job to do it.
        if ($submission->form()->get(SubmissionPayload::FOLLOW_UP) && $submission->has(SubmissionPayload::TICKET)) {
            return null;
        }

        if (! $this->isConfigured()) {
            Log::warning('Bamboo: submission '.$submission->id().' from '.$submission->form()->handle().' was not sent, because no CRM url or token is configured.');

            return null;
        }

        return new SendSubmission($submission, $connection);
    }
}
