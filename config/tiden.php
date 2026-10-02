<?php

declare(strict_types=1);

return [
    // Your project DSN: http://<publicKey>@<host:ingestPort>/<projectId>
    'dsn' => env('TIDEN_DSN'),

    // App version, e.g. "my-app@1.2.3".
    'release' => env('TIDEN_RELEASE'),

    // Defaults to the Laravel environment.
    'environment' => env('TIDEN_ENVIRONMENT', env('APP_ENV')),

    // When false (default), likely-PII is scrubbed before sending.
    'send_default_pii' => (bool) env('TIDEN_SEND_DEFAULT_PII', false),

    // Record Laravel activity as breadcrumbs on the next captured event.
    // (SQL bindings and log context are never recorded.)
    'breadcrumbs' => [
        'sql' => (bool) env('TIDEN_BREADCRUMBS_SQL', true),
        'queue' => (bool) env('TIDEN_BREADCRUMBS_QUEUE', true),
        'logs' => (bool) env('TIDEN_BREADCRUMBS_LOGS', true),
        // SQL and log messages are cut to this many bytes (on a UTF-8
        // character boundary). 0 = unlimited.
        'max_message_length' => (int) env('TIDEN_BREADCRUMBS_MAX_MESSAGE_LENGTH', 1024),
    ],
];
