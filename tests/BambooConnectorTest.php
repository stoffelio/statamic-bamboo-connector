<?php

namespace Stoffelio\BambooConnector\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\FormConnector;
use Statamic\Forms\Connectors\RecordConnectionSuccess;
use Stoffelio\BambooConnector\BambooConnector;
use Stoffelio\BambooConnector\Jobs\SendSubmission;

class BambooConnectorTest extends TestCase
{
    #[Test]
    public function it_is_registered_as_a_form_connector()
    {
        $this->assertInstanceOf(BambooConnector::class, FormConnector::find('bamboo'));
    }

    #[Test]
    public function it_sends_a_submission_the_moment_it_is_finalized()
    {
        Http::fake(['*' => Http::response(['ticket_id' => 7], 201)]);

        $this->submitTo($this->connectedForm());

        Http::assertSent(fn (Request $request) => $request->url() === 'https://crm.example.com/api/submissions'
            && $request->data()['fields'][0]['value'] === 'Jane');
    }

    #[Test]
    public function it_sends_nothing_for_a_form_that_is_not_connected()
    {
        Http::fake();

        $this->submitTo($this->form([
            $this->field('first_name', ['type' => 'short_answer', 'display' => 'First Name']),
        ]));

        Http::assertNothingSent();
    }

    #[Test]
    public function it_sends_nothing_while_the_connection_is_disabled()
    {
        Http::fake();

        $this->submitTo($this->connectedForm(['enabled' => false]));

        Http::assertNothingSent();
    }

    #[Test]
    public function it_keeps_the_uploads_alive_until_the_job_has_run()
    {
        $job = $this->jobsFor($this->connectedForm());

        // Statamic only waits for a connection job it can attach its countdown to. One it
        // cannot is counted as done when it is dispatched, and the temporary files are
        // deleted while the submission is still on its way to the CRM.
        $this->assertTrue(RecordConnectionSuccess::isAttachable($job[0] ?? new \stdClass));
    }

    #[Test]
    public function it_returns_one_job_per_submission()
    {
        $jobs = $this->jobsFor($this->connectedForm());

        $this->assertCount(1, $jobs);
        $this->assertInstanceOf(SendSubmission::class, $jobs[0]);
    }

    #[Test]
    public function it_still_sends_a_second_step_that_carries_its_first_step_ticket()
    {
        Http::fake(['*' => Http::response(['ticket_id' => 42], 200)]);

        $form = tap($this->form([
            $this->field('passport_number', ['type' => 'short_answer', 'display' => 'Passport Number']),
            $this->field('bamboo_ticket', ['type' => 'hidden', 'display' => 'Ticket']),
            $this->field('bamboo_continuation', ['type' => 'hidden', 'display' => 'Continuation']),
        ])->connections(['bamboo' => [['id' => 'abc123']]]))->save();

        $submission = $form->makeSubmission()->asPartial();
        $submission->data([
            'passport_number' => 'X1234567',
            'bamboo_ticket' => '42',
            'bamboo_continuation' => 'the-token',
        ])->save();
        $submission->finalize();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://crm.example.com/api/submissions/42/continue');
    }

    #[Test]
    public function it_sends_nothing_when_the_crm_has_not_been_configured()
    {
        config()->set('bamboo.url', null);
        config()->set('bamboo.token', null);

        Http::fake();

        $this->submitTo($this->connectedForm());

        Http::assertNothingSent();
        $this->assertFalse((new BambooConnector)->isConfigured());
    }

    private function connectedForm(array $connection = [])
    {
        $form = $this->form([
            $this->field('first_name', ['type' => 'short_answer', 'display' => 'First Name']),
        ]);

        return tap($form->connections(['bamboo' => [['id' => 'abc123'] + $connection]]))->save();
    }

    private function jobsFor($form): array
    {
        $submission = tap($form->makeSubmission()->data(['first_name' => 'Jane']))->save();

        return (new BambooConnector)->forForm($form)->finalized($submission);
    }

    private function submitTo($form)
    {
        $submission = $form->makeSubmission()->asPartial();
        $submission->data(['first_name' => 'Jane'])->save();

        return $submission->finalize();
    }
}
