<?php

return [

    'url' => env('BAMBOO_URL'),

    'token' => env('BAMBOO_TOKEN'),

    'timeout' => env('BAMBOO_TIMEOUT', 20),

    // The first step of a two-step form is sent while the visitor waits, so this is a
    // budget for how long a page may hang on the CRM before giving up on the second step.
    'first_step_timeout' => env('BAMBOO_FIRST_STEP_TIMEOUT', 4),

    'date_format' => 'j F Y',

    // Where the follow-up page lives on this site. The CRM is told the same path, as the
    // organization's follow_up_base_url, and builds its links from it.
    'follow_up_path' => env('BAMBOO_FOLLOW_UP_PATH', 'follow-up'),

    'organization_field' => 'target_organization',

    'organization_id_field' => 'crm_id',

    'max_file_size' => 20480,

    'allowed_extensions' => [
        'pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif',
        'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'rtf', 'zip',
    ],

    'image_max_dimension' => 2400,

    'image_quality' => 88,

];
