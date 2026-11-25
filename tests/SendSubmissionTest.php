<?php

namespace Stoffelio\BambooConnector\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Forms\Uploaders\FormFileUpload;
use Stoffelio\BambooConnector\Jobs\SendSubmission;

class SendSubmissionTest extends TestCase
{
    #[Test]
    public function it_posts_a_submission_as_json_when_it_has_no_files()
    {
        Http::fake([
            '*' => Http::response(['ticket_id' => 7], 201),
        ]);

        $submission = $this->submission([
            $this->field('first_name', ['type' => 'short_answer', 'display' => 'First Name']),
            $this->field('requested_services', ['type' => 'checkboxes', 'display' => 'Requested Services', 'options' => [
                ['key' => 'checkup', 'value' => 'Checkup'],
            ]]),
        ], ['first_name' => 'Jane', 'requested_services' => ['checkup']]);

        (new SendSubmission($submission))->handle();

        Http::assertSent(function (Request $request) use ($submission) {
            $body = $request->data();

            return $request->url() === 'https://crm.example.com/api/submissions'
                && $request->hasHeader('Authorization', 'Bearer test-token')
                && $body['external_id'] === $submission->id()
                && $body['form'] === ['handle' => 'booking', 'title' => 'Booking']
                && $body['expects_follow_up'] === false
                && $body['fields'][0]['handle'] === 'first_name'
                && $body['fields'][1]['value'] === ['checkup']
                && $body['fields'][1]['display'] === 'Checkup';
        });
    }

    #[Test]
    public function it_sends_a_file_under_its_own_field_handle()
    {
        Storage::fake('local');
        Http::fake(['*' => Http::response(['ticket_id' => 7], 201)]);

        $form = $this->form([
            $this->field('first_name', ['type' => 'short_answer', 'display' => 'First Name']),
            $this->field('passport_copy', ['type' => 'upload', 'display' => 'Passport Copy', 'max_files' => 1]),
        ]);

        $submission = tap($form->makeSubmission())->save();

        $path = FormFileUpload::field(['handle' => 'passport_copy', 'max_files' => 1], $submission->id())
            ->upload([UploadedFile::fake()->create('scan.pdf', 12, 'application/pdf')]);

        $submission->data(['first_name' => 'Jane', 'passport_copy' => $path])->save();

        (new SendSubmission($form->submission($submission->id())))->handle();

        Http::assertSent(function (Request $request) {
            $body = $request->body();

            return str_contains($body, 'name="files[passport_copy][]"')
                && str_contains($body, 'filename="scan.pdf"')
                && str_contains($body, 'name="fields[1][type]"')
                && str_contains($body, 'name="fields[1][handle]"')
                && str_contains($body, 'passport_copy');
        });
    }

    #[Test]
    public function it_leaves_no_temporary_copies_behind()
    {
        Storage::fake('local');
        Http::fake(['*' => Http::response(['ticket_id' => 7], 201)]);

        $before = glob(sys_get_temp_dir().'/bamboo-*') ?: [];

        $form = $this->form([
            $this->field('passport_copy', ['type' => 'upload', 'display' => 'Passport Copy', 'max_files' => 1]),
        ]);

        $submission = tap($form->makeSubmission())->save();

        $path = FormFileUpload::field(['handle' => 'passport_copy', 'max_files' => 1], $submission->id())
            ->upload([UploadedFile::fake()->create('scan.pdf', 12, 'application/pdf')]);

        $submission->data(['passport_copy' => $path])->save();

        (new SendSubmission($form->submission($submission->id())))->handle();

        $this->assertSame($before, glob(sys_get_temp_dir().'/bamboo-*') ?: []);
    }

    #[Test]
    public function it_does_not_attach_a_file_type_the_crm_would_refuse()
    {
        Storage::fake('local');
        Http::fake(['*' => Http::response(['ticket_id' => 7], 201)]);

        $form = $this->form([
            $this->field('passport_copy', ['type' => 'upload', 'display' => 'Passport Copy', 'max_files' => 1]),
        ]);

        $submission = tap($form->makeSubmission())->save();

        $path = FormFileUpload::field(['handle' => 'passport_copy', 'max_files' => 1], $submission->id())
            ->upload([UploadedFile::fake()->create('payload.php', 2)]);

        $submission->data(['passport_copy' => $path])->save();

        (new SendSubmission($form->submission($submission->id())))->handle();

        Http::assertSent(fn (Request $request) => $request->isJson()
            && ! str_contains($request->body(), 'payload.php')
            && $request->data()['fields'][0]['value'] === []);
    }

    #[Test]
    public function it_treats_a_submission_the_crm_already_has_as_sent()
    {
        Http::fake(['*' => Http::response(['ticket_id' => 7, 'duplicate' => true], 200)]);

        $submission = $this->submission([
            $this->field('first_name', ['type' => 'short_answer', 'display' => 'First Name']),
        ], ['first_name' => 'Jane']);

        (new SendSubmission($submission))->handle();

        Http::assertSentCount(1);
    }

    #[Test]
    public function it_throws_when_the_crm_fails_so_the_queue_tries_again()
    {
        Http::fake(['*' => Http::response(['message' => 'Server Error'], 500)]);

        $submission = $this->submission([
            $this->field('first_name', ['type' => 'short_answer', 'display' => 'First Name']),
        ], ['first_name' => 'Jane']);

        $this->expectException(RequestException::class);

        (new SendSubmission($submission))->handle();
    }

    #[Test]
    public function it_announces_a_second_step_so_the_crm_can_flag_what_never_arrived()
    {
        Http::fake(['*' => Http::response(['ticket_id' => 7], 201)]);

        $submission = $this->submission([
            $this->field('first_name', ['type' => 'short_answer', 'display' => 'First Name']),
        ], ['first_name' => 'Jane']);

        (new SendSubmission($submission, [], expectsFollowUp: true))->handle();

        Http::assertSent(fn (Request $request) => $request->data()['expects_follow_up'] === true);
    }
}
