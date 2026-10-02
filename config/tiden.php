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

    // Seconds the synchronous send may take. Null picks 5.0 in the console
    // (queue workers, commands) and 2.0 for web requests.
    'http_timeout' => env('TIDEN_HTTP_TIMEOUT'),

    // How many breadcrumbs ride along with one event (oldest dropped first).
    'max_breadcrumbs' => (int) env('TIDEN_MAX_BREADCRUMBS', 100),

    // Last-chance hook to mutate or drop an event: [Class::class, 'staticMethod']
    // or the class-string of an invokable resolved from the container. Closures
    // are ignored because they break `php artisan config:cache`.
    'before_send' => null,

    // Start every queue job and top-level Artisan command with fresh
    // breadcrumbs, and discard the tags a job sets when it finishes.
    'reset_scope' => (bool) env('TIDEN_RESET_SCOPE', true),

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
