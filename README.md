# Bamboo Connector

Sends Statamic form submissions to the Bamboo CRM, where each one becomes a ticket.

Requires Statamic 6.44 or later, which is where form connectors arrived.

## Installing

    composer require stoffelio/statamic-bamboo-connector

Then set the CRM's base URL and the site's own organization token:

    BAMBOO_URL=https://crm.example.com
    BAMBOO_TOKEN=...

The token is issued per organization by the CRM and identifies the site. Until both are
set the connector says so in the control panel and sends nothing.

## Connecting a form

Each form has a **Connect** area. Add a Bamboo connection there and every submission goes
up. One connection is enough; a second would send the same submission twice. Conditions
work as they do for emails and webhooks.

There is deliberately nothing else to configure. The CRM owns the vocabulary, so a field
it recognises lands on the ticket or the patient and anything else becomes a row in a
comment — which is why adding a field to a form is not a code change on either side.

## What the CRM recognises

Name a field for what it means and it maps itself:

| Handle | Becomes |
|---|---|
| `title` | the ticket title |
| `source_url` | where the form was filled in |
| `target_organization` | the partner the ticket is for |
| `first_name`, `last_name`, `email`, `phone`, `gender`, `date_of_birth`, `nationality`, `passport_number` | the patient |
| `patients` | a grid, one row per patient |
| `notes`, and anything else | a label and value row in a comment, in form order |

`target_organization` may hold a CRM organization id, an organization name, or the id of a
Statamic entry carrying a `crm_id` — a hospital picker, say, which is resolved here because
the CRM has no idea what a Statamic id is.

`notes` is deliberately not a patient field: the patient's notes belong to the team, so a
visitor's own message becomes a comment row. Name a free-text field whatever reads best on
the form — it lands in the same place either way.

A file field whose handle contains **`passport`** starts the CRM's passport reader on
arrival, so name it `passport_copy` rather than `document_1` when that is what it is.

## What is sent

Every field carries both halves: `value`, which the CRM maps on, and `display`, which it
prints. A date arrives as `2026-10-14` and "14 October 2026" at once, a dictionary as
`THA` and "Thailand", a checkbox list as its keys and its labels.

    {
      "external_id": "1795380208.6407",
      "form": { "handle": "dental_checkup_form", "title": "Dental Checkup Form" },
      "expects_follow_up": false,
      "fields": [
        { "handle": "first_name", "label": "First Name", "type": "text", "value": "Jane", "display": "Jane" },
        { "handle": "nationality", "label": "Nationality", "type": "dictionary", "value": "THA", "display": "Thailand" },
        { "handle": "passport_copy", "label": "Passport Copy", "type": "file", "value": ["scan.pdf"], "display": "scan.pdf" }
      ]
    }

Files go up as multipart, keyed by the field they came from — `files[passport_copy][]`.
A flat `files[]` is refused by the CRM, because its mime and size rules hang off the
handle.

Submissions with no files are sent as JSON, so a boolean stays a boolean and a checkbox
list stays a list.

The spam defences, the fields that only lay out the form, and `confirm_*` inputs are left
out: a comment row reading "Confirm EMail" or holding a Turnstile token is noise.

## Files

A file is read back through Statamic's own upload config rather than a hardcoded path,
copied to a temporary file, checked against the CRM's own mime and size limits, and
deleted afterwards. An image larger than `bamboo.image_max_dimension` is scaled down first
if `intervention/image` is installed, and sent as it is if it is not.

A file the CRM would refuse is dropped, logged, and left out of the field row rather than
costing the whole submission — the lead matters more than the attachment.

## Two-step forms

A short form captures the lead, and the CRM decides where the rest of it is asked for.

Mark the first form as expecting a follow-up, in its own YAML rather than as a field — what
makes a form a first step was decided when it was built, and a hidden field would let
whoever is filling it in decide instead:

    bamboo_follow_up: true

That form is sent **in the request that submitted it**, not from the queue, because the
page is waiting on the answer. The CRM issues a data request for the ticket and hands back
its link, which rides home in the submission JSON as `bamboo_follow_up_url` — set after the
submission was saved, so it is never written to disk. The page then sends the visitor there.

The CRM puts the same link in its automatic reply, so there is one page reached two ways and
somebody who closes the tab can finish from their inbox. Nothing is lost when the CRM does
not answer in time either: the timeout is short (`bamboo.first_step_timeout`, four seconds),
the connection job still delivers the submission, and the email still carries the link.

A first step carrying a file upload is left to the queue, since an inline send is invisible
to the countdown that keeps the temporary files alive. It will not hand off.

## The follow-up page

The addon ships the page the CRM links to, at `/{bamboo.follow_up_path}/{id}/{token}` —
`follow-up` by default. Tell the CRM the same path, as the organization's
`follow_up_base_url`, and it builds its links from it.

The CRM composes what to ask, so a question added there needs no deploy here. The page
renders patient fields, file fields, free-text questions, and a **passport group** that
offers an upload *or* the fields it would have filled, whichever the patient prefers.

It renders **inside the site's own layout**, through Statamic rather than as a page of its
own, so it carries the header, the footer and everything else in the cascade. The shipped
templates are deliberately plain — publish them and they land in `resources/views`, where
they can use the site's own partials and where a site's Tailwind can finally see their
classes:

    php artisan vendor:publish --tag=bamboo-views

`bamboo.follow_up_layout` names the layout, `layout` by default.

Two things it does that are not optional. It sends `Referrer-Policy: no-referrer` and
`X-Robots-Tag: noindex`, because a token in a URL otherwise leaks to every third-party
origin the page loads. And it must be **excluded from static caching** — it is different for
every visitor, and a cached copy cannot tell somebody their link has already been used:

    // config/statamic/static_caching.php
    'exclude' => ['urls' => ['/follow-up/*']],

## Retries## Retries

The job is queued, retried five times with a growing backoff, and logs through `failed()`
when it finally gives up. Retrying is safe: the CRM treats `external_id` as idempotent and
answers a repeat with the original ticket id.

Queueing is also what keeps the uploads alive. Statamic deletes a submission's temporary
files once every connection has reported success, and it can only wait for a job it is
able to attach its own middleware to — so this job must stay `ShouldQueue`.

## Developing

    composer install
    npm install
    npm run build
    composer check

The control panel component is built against `@statamic/cms`, which is read out of the
addon's own `vendor` directory, so `composer install` comes first.
