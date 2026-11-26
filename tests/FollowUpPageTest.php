<?php

namespace Stoffelio\BambooConnector\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class FollowUpPageTest extends TestCase
{
    private const OPEN = [
        'state' => 'open',
        'message' => 'A few details before we can arrange anything.',
        'greeting_name' => 'Jane',
        'ticket_fields' => [],
        'sections' => [[
            'key' => 'patient_1',
            'label' => 'Your details',
            'fields' => [
                [
                    'key' => 'passport', 'type' => 'passport', 'label' => 'Passport', 'required' => true,
                    'instructions' => 'Upload the photo page or type them in.', 'multiple' => false,
                    'fields' => [
                        ['key' => 'first_name', 'type' => 'patient', 'label' => 'First Name', 'input' => 'text', 'value' => 'Jane', 'required' => true],
                        ['key' => 'date_of_birth', 'type' => 'patient', 'label' => 'Date of Birth', 'input' => 'date', 'value' => '', 'required' => true],
                        ['key' => 'gender', 'type' => 'patient', 'label' => 'Gender', 'input' => 'select', 'value' => '', 'required' => true,
                            'options' => ['female' => 'Female', 'male' => 'Male']],
                    ],
                ],
                ['key' => 'phone', 'type' => 'patient', 'label' => 'Phone Number', 'input' => 'tel', 'value' => '', 'required' => true,
                    'dialling_codes' => ['TH' => ['name' => 'Thailand', 'code' => 66], 'GB' => ['name' => 'United Kingdom', 'code' => 44]]],
                ['key' => 'anything_else', 'type' => 'question', 'label' => 'Anything else?', 'input' => 'textarea', 'value' => '', 'required' => false],
            ],
        ]],
    ];

    /**
     * Rendered inside the site's own layout rather than a page of its own, so it carries
     * the header, the footer and everything else a page of the site carries.
     */
    #[Test]
    public function it_renders_as_a_page_of_the_site()
    {
        Http::fake(['*' => Http::response(self::OPEN)]);

        $this->get('/follow-up/1795/the-token')->assertOk()->assertSee('Site chrome');
    }

    /**
     * The layout builds the document title out of the cascade, and a page with nothing in
     * it there arrives in somebody's tab as a bare site name.
     */
    #[Test]
    public function it_gives_the_layout_a_title_to_render()
    {
        Http::fake(['*' => Http::response(self::OPEN)]);

        $this->get('/follow-up/1795/the-token')->assertSee('<title>A few more details</title>', false);
    }

    #[Test]
    public function it_renders_what_the_crm_asked_for()
    {
        Http::fake(['*' => Http::response(self::OPEN)]);

        $response = $this->get('/follow-up/1795/the-token');

        $response->assertOk()
            ->assertSee('Hello Jane')
            ->assertSee('A few details before we can arrange anything.')
            ->assertSee('Your details')
            ->assertSee('Phone Number')
            ->assertSee('Anything else?');
    }

    #[Test]
    public function it_offers_the_upload_and_the_typing_as_a_choice()
    {
        Http::fake(['*' => Http::response(self::OPEN)]);

        $response = $this->get('/follow-up/1795/the-token');

        $response->assertSee('files[passport][]', false)
            ->assertSee('Upload Passport')
            ->assertSee('Manual Entry')
            ->assertSee('answers[patient_1][date_of_birth]', false)
            ->assertSee('role="tablist"', false);
    }

    #[Test]
    public function it_shows_which_enquiry_the_request_belongs_to()
    {
        Http::fake(['*' => Http::response(self::OPEN + ['ticket_id' => 4242])]);

        $this->get('/follow-up/1795/the-token')->assertSee('Enquiry #4242');
    }

    /**
     * A number only a Thai phone could dial is no use to a clinic ringing from elsewhere,
     * so the country is asked for rather than hoped for.
     */
    #[Test]
    public function it_asks_for_a_phone_number_with_its_country_code()
    {
        Http::fake(['*' => Http::response(self::OPEN)]);

        $response = $this->get('/follow-up/1795/the-token');

        $response->assertSee('Thailand (+66)', false)
            ->assertSee('United Kingdom (+44)', false)
            // Closed, it shows a flag and a code; open, the whole country name.
            ->assertSee("data-short=\"\u{1F1F9}\u{1F1ED} +66\"", false)
            // The two controls post as one value, in the shape the CRM stores.
            ->assertSee('x-bind:value="combined"', false)
            ->assertSee('name="answers[patient_1][phone]"', false);
    }

    /**
     * The control needs the dialling codes, and only a CRM new enough to send them has
     * them. Until that one is deployed the field is still a phone field, not a text box.
     */
    #[Test]
    public function it_falls_back_to_a_plain_tel_input_when_no_codes_arrive()
    {
        $withoutCodes = self::OPEN;
        $withoutCodes['sections'][0]['fields'][1] = [
            'key' => 'phone', 'type' => 'patient', 'label' => 'Phone Number',
            'input' => 'phone', 'value' => '', 'required' => true,
        ];

        Http::fake(['*' => Http::response($withoutCodes)]);

        $this->get('/follow-up/1795/the-token')
            ->assertSee('type="tel"', false)
            ->assertSee('name="answers[patient_1][phone]"', false)
            ->assertDontSee('Country code');
    }

    /**
     * The widest of the 245 options is "+599 Bonaire, Sint Eustatius and Saba", and a
     * select sizes itself to its widest option — which pushed the number input out of the
     * row entirely on anything narrower than a desk.
     */
    #[Test]
    public function it_keeps_the_country_select_from_sizing_itself_to_its_longest_option()
    {
        Http::fake(['*' => Http::response(self::OPEN)]);

        $html = $this->get('/follow-up/1795/the-token')->getContent();

        preg_match('/<select[^>]*aria-label="Country code"[^>]*>/', $html, $select);
        preg_match('/<input[^>]*type="tel"[^>]*>/s', $html, $number);

        // The select is an invisible overlay on a fixed-width box, so its own width can
        // never push the number input out of the row however long an option is.
        $this->assertStringContainsString('absolute inset-0', $select[0] ?? '');
        $this->assertStringContainsString('opacity-0', $select[0] ?? '');
        $this->assertStringContainsString('min-w-0', $number[0] ?? '');
    }

    /**
     * Neither half of the either/or group may carry a plain `required`. The manual fields
     * sit in a hidden tab panel, and a browser refuses to submit a form holding a required
     * control it cannot focus — so an uploaded passport produced a button that did nothing
     * and an error only the console saw.
     */
    #[Test]
    public function it_never_requires_a_passport_field_the_tab_is_hiding()
    {
        Http::fake(['*' => Http::response(self::OPEN)]);

        $html = $this->get('/follow-up/1795/the-token')->getContent();

        preg_match_all('/<(?:input|select|textarea)[^>]*answers\[patient_1\]\[(?:first_name|date_of_birth|gender)\][^>]*>/s', $html, $manual);
        preg_match('/<input[^>]*name="files\[passport\]\[\]"[^>]*>/s', $html, $upload);

        $this->assertCount(3, $manual[0]);

        foreach ($manual[0] as $field) {
            $this->assertDoesNotMatchRegularExpression('/\srequired(?![-\w=])/', $field);
            $this->assertStringContainsString('x-bind:required="tab === \'manual\' && !uploaded"', $field);
        }

        // The upload is the other half of the same rule, and it is hidden on the other tab.
        $this->assertDoesNotMatchRegularExpression('/\srequired(?![-\w=])/', $upload[0] ?? '');
        $this->assertStringContainsString('x-bind:required="tab === \'upload\' && !typed"', $upload[0] ?? '');
    }

    /**
     * The link is the only thing standing between this page and the internet, so a token in
     * the path must not ride out on a Referer header to a font or a captcha.
     */
    #[Test]
    public function it_keeps_the_token_out_of_referrers_and_search_results()
    {
        Http::fake(['*' => Http::response(self::OPEN)]);

        $this->get('/follow-up/1795/the-token')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    #[Test]
    public function it_never_puts_the_site_token_on_the_page()
    {
        Http::fake(['*' => Http::response(self::OPEN)]);

        $this->get('/follow-up/1795/the-token')->assertDontSee(config('bamboo.token'));
    }

    #[Test]
    public function it_says_so_when_the_request_was_already_completed()
    {
        Http::fake(['*' => Http::response(['state' => 'submitted'])]);

        $this->get('/follow-up/1795/the-token')
            ->assertStatus(410)
            ->assertSee('we have your details')
            ->assertDontSee('Send these details');
    }

    #[Test]
    public function it_shows_one_dead_end_for_every_other_state()
    {
        foreach (['expired', 'revoked', 'unknown'] as $state) {
            Http::fake(['*' => Http::response(['state' => $state])]);

            $this->get('/follow-up/1795/the-token')
                ->assertStatus(410)
                ->assertSee('no longer available');
        }
    }

    #[Test]
    public function it_forwards_the_answers_to_the_crm()
    {
        Http::fake(['*' => Http::response(['state' => 'submitted'])]);

        $this->post('/follow-up/1795/the-token', [
            'answers' => ['patient_1' => ['phone' => '+447700900123', 'date_of_birth' => '1991-04-02']],
        ])->assertOk()->assertSee('Thank you');

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->url() === 'https://crm.example.com/api/data-requests/1795/the-token'
                && $body['answers']['patient_1']['phone'] === '+447700900123';
        });
    }

    #[Test]
    public function it_forwards_an_uploaded_passport_under_its_own_key()
    {
        Http::fake(['*' => Http::response(['state' => 'submitted'])]);

        $this->post('/follow-up/1795/the-token', [
            'answers' => ['patient_1' => ['phone' => '+447700900123']],
            'files' => ['passport' => [UploadedFile::fake()->image('passport.jpg')]],
        ])->assertOk();

        Http::assertSent(fn (Request $request) => str_contains($request->body(), 'name="files[passport][]"')
            && str_contains($request->body(), 'filename="passport.jpg"'));
    }

    /**
     * A mail client fetching the link to check it is safe must not count as the patient
     * having seen the page, so the ping is made by the browser rather than by this.
     */
    #[Test]
    public function it_tells_the_crm_when_a_browser_really_rendered_the_page()
    {
        Http::fake(['*' => Http::response(self::OPEN)]);

        $this->get('/follow-up/1795/the-token')->assertSee('/rendered', false);

        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/rendered'));

        $this->post('/follow-up/1795/the-token/rendered')->assertNoContent();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://crm.example.com/api/data-requests/1795/the-token/rendered');
    }

    #[Test]
    public function it_keeps_the_page_up_when_the_crm_cannot_be_reached()
    {
        Http::fake(fn () => Http::failedConnection());

        $this->get('/follow-up/1795/the-token')
            ->assertStatus(404)
            ->assertSee('no longer available');
    }
}
