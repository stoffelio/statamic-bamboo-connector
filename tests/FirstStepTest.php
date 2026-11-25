<?php

namespace Stoffelio\BambooConnector\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Forms\SubmitForm;
use Statamic\Forms\Uploaders\FormFileUpload;
use Stoffelio\BambooConnector\BambooConnector;

class FirstStepTest extends TestCase
{
    #[Test]
    public function it_hands_the_page_a_ticket_and_a_token_to_carry_into_the_second_step()
    {
        Http::fake(['*' => Http::response(['ticket_id' => 42, 'continuation_token' => 'the-token'], 201)]);

        $submission = $this->submitFirstStep();

        $this->assertSame(42, $submission->get('bamboo_ticket'));
        $this->assertSame('the-token', $submission->get('bamboo_continuation'));

        // Whatever is set on the submission after it was saved is what the response
        // carries, and nothing more: the page reads these two out of the JSON.
        $this->assertArrayHasKey('bamboo_continuation', $submission->values()->all());
    }

    /**
     * The token is set on the submission after it was saved, which is what keeps it out of
     * the file — and this project commits `storage/forms`, so a token on disk would be a
     * token in git history long after it was spent.
     */
    #[Test]
    public function it_does_not_write_the_token_to_disk()
    {
        Http::fake(['*' => Http::response(['ticket_id' => 42, 'continuation_token' => 'the-token'], 201)]);

        $submission = $this->submitFirstStep();

        $stored = file_get_contents($submission->path());

        $this->assertStringNotContainsString('the-token', $stored);
        $this->assertStringNotContainsString('bamboo_continuation', $stored);
        $this->assertStringContainsString('first_name: Jane', $stored);
    }

    #[Test]
    public function it_sends_the_first_step_once()
    {
        Http::fake(['*' => Http::response(['ticket_id' => 42, 'continuation_token' => 'the-token'], 201)]);

        $this->submitFirstStep();

        Http::assertSentCount(1);
    }

    #[Test]
    public function it_still_captures_the_lead_when_the_crm_does_not_answer_in_time()
    {
        Http::fake(function () {
            static $call = 0;

            return ++$call === 1
                ? Http::failedConnection()
                : Http::response(['ticket_id' => 42], 201);
        });

        $submission = $this->submitFirstStep();

        // The inline attempt failed, so the connection job sent it instead — the lead is
        // in the CRM and only the offer of a second step was lost.
        $this->assertNull($submission->get('bamboo_continuation'));
        Http::assertSentCount(2);
    }

    #[Test]
    public function it_leaves_a_first_step_carrying_a_file_to_the_queue()
    {
        Storage::fake('local');
        Http::fake(['*' => Http::response(['ticket_id' => 42, 'continuation_token' => 'the-token'], 201)]);

        $form = $this->connectedForm([
            $this->field('first_name', ['type' => 'short_answer', 'display' => 'First Name']),
            $this->field('passport_copy', ['type' => 'upload', 'display' => 'Passport Copy', 'max_files' => 1]),
        ]);

        $submission = tap($form->makeSubmission()->asPartial())->save();

        $path = FormFileUpload::field(['handle' => 'passport_copy', 'max_files' => 1], $submission->id())
            ->upload([UploadedFile::fake()->create('scan.pdf', 12, 'application/pdf')]);

        $submission->data(['first_name' => 'Jane', 'passport_copy' => $path])->save();
        $submission->finalize();

        // Sent, but by the job: an inline send is invisible to the countdown that keeps
        // the upload alive, so a first step with a file waits its turn.
        Http::assertSentCount(1);
        $this->assertNull($submission->get('bamboo_continuation'));
    }

    #[Test]
    public function it_ignores_a_form_that_is_not_a_first_step()
    {
        Http::fake(['*' => Http::response(['ticket_id' => 42], 201)]);

        $form = tap($this->form([
            $this->field('first_name', ['type' => 'short_answer', 'display' => 'First Name']),
        ])->connections([BambooConnector::handle() => [['id' => 'abc123']]]))->save();

        $submission = $form->makeSubmission()->asPartial();
        $submission->data(['first_name' => 'Jane'])->save();
        $submission->finalize();

        $this->assertNull($submission->get('bamboo_continuation'));
        Http::assertSentCount(1);
    }

    #[Test]
    public function it_sends_nothing_inline_for_a_form_that_is_not_connected()
    {
        Http::fake();

        $form = tap($this->form([
            $this->field('first_name', ['type' => 'short_answer', 'display' => 'First Name']),
        ])->set('bamboo_follow_up', true))->save();

        $submission = $form->makeSubmission()->asPartial();
        $submission->data(['first_name' => 'Jane'])->save();
        $submission->finalize();

        Http::assertNothingSent();
    }

    private function submitFirstStep()
    {
        $form = $this->connectedForm([
            $this->field('first_name', ['type' => 'short_answer', 'display' => 'First Name']),
            $this->field('email', ['type' => 'email', 'display' => 'EMail']),
        ]);

        return (new SubmitForm)
            ->form($form)
            ->submit(['first_name' => 'Jane', 'email' => 'jane@example.com'])
            ->submission;
    }

    /** A first step: connected to the CRM, and marked on the form as expecting a second. */
    private function connectedForm(array $fields)
    {
        return tap(
            $this->form($fields)
                ->set('bamboo_follow_up', true)
                ->connections([BambooConnector::handle() => [['id' => 'abc123']]])
        )->save();
    }
}
