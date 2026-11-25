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
| `first_name`, `last_name`, `email`, `phone`, `gender`, `date_of_birth`, `nationality`, `passport_number`, `notes` | the patient |
| `patients` | a grid, one row per patient |
| anything else | a label and value row in a comment, in form order |

`target_organization` may hold a CRM organization id, an organization name, or the id of a
Statamic entry carrying a `crm_id` — a hospital picker, say, which is resolved here because
the CRM has no idea what a Statamic id is.

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

A short form that captures the lead, and a longer one that appears the moment it lands and
appends to the ticket the first one opened.

Mark the first form as expecting a second, in its own YAML rather than as a field — what
makes a form a first step was decided when it was built, and a hidden field would let
whoever is filling it in decide instead:

    bamboo_follow_up: true

That form is then sent **in the request that submitted it**, not from the queue, because the
page needs the ticket id and the continuation token that come back with it. They ride back
in the submission JSON as `bamboo_ticket` and `bamboo_continuation`, set after the
submission was saved so neither is written to disk. The timeout is short
(`bamboo.first_step_timeout`, four seconds) and every failure is swallowed: the connection
job still sends the lead, and only the offer of a second step is lost.

The second form carries both values back as hidden fields:

    -
      handle: bamboo_ticket
      field: { type: hidden, display: Ticket }
    -
      handle: bamboo_continuation
      field: { type: hidden, display: Continuation }

A submission carrying them is posted to `POST /api/submissions/{ticket}/continue` instead,
and the CRM refuses it once the step has been completed. Every `bamboo_*` handle is stripped
from the payload, so none of this reaches the CRM as patient data.

A first step carrying a file upload is left to the queue, since an inline send is invisible
to the countdown that keeps the temporary files alive. It will not offer a second step.

**Rendering:** whatever renders these two forms has to fill the hidden fields from the first
step's response, and must not let Alpine bind a hidden input — a form's `x-data` carries each
field's default as it was written, so a binding posts `current_url` where the input holds the
resolved URL.

## Retries

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
