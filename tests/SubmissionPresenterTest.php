<?php

namespace Stoffelio\BambooConnector\Tests;

use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Stoffelio\BambooConnector\Submissions\Attachment;
use Stoffelio\BambooConnector\Submissions\SubmissionPresenter;

class SubmissionPresenterTest extends TestCase
{
    #[Test]
    public function it_sends_the_fields_in_form_order()
    {
        $submission = $this->submission([
            $this->field('first_name', ['type' => 'short_answer', 'display' => 'First Name']),
            $this->field('last_name', ['type' => 'short_answer', 'display' => 'Last Name']),
            $this->field('notes', ['type' => 'long_answer', 'display' => 'Anything else?']),
        ], ['notes' => 'Later', 'last_name' => 'Doe', 'first_name' => 'Jane']);

        $handles = collect(SubmissionPresenter::for($submission)->fields())->pluck('handle')->all();

        $this->assertSame(['first_name', 'last_name', 'notes'], $handles);
    }

    #[Test]
    public function it_carries_a_label_and_both_a_value_and_a_display()
    {
        $submission = $this->submission([
            $this->field('gender', ['type' => 'dropdown', 'display' => 'Gender', 'options' => [
                ['key' => 'female', 'value' => 'Female'],
                ['key' => 'male', 'value' => 'Male'],
            ]]),
        ], ['gender' => 'female']);

        $this->assertSame([
            'handle' => 'gender',
            'label' => 'Gender',
            'type' => 'select',
            'value' => 'female',
            'display' => 'Female',
        ], SubmissionPresenter::for($submission)->fields()[0]);
    }

    #[Test]
    public function it_resolves_every_checked_option_to_its_label()
    {
        $submission = $this->submission([
            $this->field('requested_services', ['type' => 'checkboxes', 'display' => 'Requested Services', 'options' => [
                ['key' => 'checkup', 'value' => 'Checkup'],
                ['key' => 'cleaning', 'value' => 'Cleaning'],
                ['key' => 'whitening', 'value' => 'Whitening'],
            ]]),
        ], ['requested_services' => ['checkup', 'whitening']]);

        $row = SubmissionPresenter::for($submission)->fields()[0];

        $this->assertSame(['checkup', 'whitening'], $row['value']);
        $this->assertSame('Checkup, Whitening', $row['display']);
    }

    #[Test]
    public function it_resolves_a_dictionary_to_its_own_label()
    {
        $submission = $this->submission([
            $this->field('nationality', ['type' => 'dictionary', 'display' => 'Nationality', 'dictionary' => ['type' => 'countries', 'emojis' => false], 'max_items' => 1]),
        ], ['nationality' => 'THA']);

        $row = SubmissionPresenter::for($submission)->fields()[0];

        $this->assertSame('THA', $row['value']);
        $this->assertSame('Thailand', $row['display']);
    }

    #[Test]
    public function it_sends_a_date_as_an_iso_value_and_a_human_display()
    {
        $submission = $this->submission([
            $this->field('preferred_date', ['type' => 'date_picker', 'display' => 'Preferred Appointment Date']),
        ], ['preferred_date' => '2026-10-14']);

        $row = SubmissionPresenter::for($submission)->fields()[0];

        $this->assertSame('2026-10-14', $row['value']);
        $this->assertSame('14 October 2026', $row['display']);
    }

    #[Test]
    public function it_sends_a_date_typed_into_a_text_field_the_same_way()
    {
        $submission = $this->submission([
            $this->field('preferred_date', ['type' => 'short_answer', 'input_type' => 'date', 'display' => 'Preferred Appointment Date']),
        ], ['preferred_date' => '2026-10-14']);

        $row = SubmissionPresenter::for($submission)->fields()[0];

        $this->assertSame('2026-10-14', $row['value']);
        $this->assertSame('14 October 2026', $row['display']);
    }

    #[Test]
    public function it_strips_the_fields_nobody_should_read_in_a_comment()
    {
        $form = $this->form([
            $this->field('step_one', ['type' => 'heading', 'display' => 'About you']),
            $this->field('email', ['type' => 'email', 'display' => 'EMail']),
            $this->field('confirm_email', ['type' => 'email', 'display' => 'Confirm EMail']),
            $this->field('first_name', ['type' => 'short_answer', 'display' => 'First Name']),
        ]);

        $submission = tap($form->makeSubmission()->data([
            'email' => 'jane@example.com',
            'confirm_email' => 'jane@example.com',
            'first_name' => 'Jane',
            'honeypot' => '',
        ]))->save();

        $handles = collect(SubmissionPresenter::for($submission)->fields())->pluck('handle')->all();

        $this->assertSame(['email', 'first_name'], $handles);
    }

    #[Test]
    public function it_resolves_the_target_organization_to_the_crm_id_on_the_entry()
    {
        Collection::make('hospitals')->save();
        $entry = tap(Entry::make()->collection('hospitals')->slug('yanhee')->data([
            'title' => 'Yanhee Hospital',
            'crm_id' => '11',
        ]))->save();

        $submission = $this->submission([
            $this->field('target_organization', ['type' => 'short_answer', 'display' => 'Hospital']),
        ], ['target_organization' => $entry->id()]);

        $row = SubmissionPresenter::for($submission)->fields()[0];

        $this->assertSame('11', $row['value']);
        $this->assertSame('Yanhee Hospital', $row['display']);
    }

    #[Test]
    public function it_leaves_a_target_organization_the_crm_can_already_read_alone()
    {
        $submission = $this->submission([
            $this->field('target_organization', ['type' => 'short_answer', 'display' => 'Hospital']),
        ], ['target_organization' => '11']);

        $this->assertSame('11', SubmissionPresenter::for($submission)->fields()[0]['value']);
    }

    #[Test]
    public function it_announces_a_file_by_its_handle_so_the_crm_can_tie_it_to_the_upload()
    {
        $submission = $this->submission([
            $this->field('passport_copy', ['type' => 'upload', 'display' => 'Passport Copy', 'max_files' => 1]),
        ], ['passport_copy' => '1795380208.6407/passport_copy/scan.pdf']);

        $attachment = new Attachment('passport_copy', 'scan.pdf', '/tmp/bamboo-xyz', 'application/pdf');

        $row = SubmissionPresenter::for($submission, [$attachment])->fields()[0];

        $this->assertSame('file', $row['type']);
        $this->assertSame('Passport Copy', $row['label']);
        $this->assertSame(['scan.pdf'], $row['value']);
    }

    #[Test]
    public function it_does_not_name_a_file_that_is_not_going_up()
    {
        $submission = $this->submission([
            $this->field('passport_copy', ['type' => 'upload', 'display' => 'Passport Copy', 'max_files' => 1]),
        ], ['passport_copy' => '1795380208.6407/passport_copy/payload.php']);

        $row = SubmissionPresenter::for($submission)->fields()[0];

        $this->assertSame([], $row['value']);
        $this->assertSame('', $row['display']);
    }

    #[Test]
    public function it_sends_an_unanswered_field_as_an_empty_string_rather_than_dropping_it()
    {
        $submission = $this->submission([
            $this->field('phone', ['type' => 'phone', 'display' => 'Phone']),
        ], []);

        $row = SubmissionPresenter::for($submission)->fields()[0];

        $this->assertSame('', $row['value']);
        $this->assertSame('', $row['display']);
    }
}
