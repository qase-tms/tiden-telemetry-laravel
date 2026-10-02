# tiden/telemetry-laravel

Laravel integration for [Tiden](https://tiden.ai) error tracking. Auto-captures
every exception Laravel reports and sends it to your Tiden project. Built on
[`tiden/telemetry-php`](https://github.com/qase-tms/tiden-telemetry-php).

## Install

```bash
composer require tiden/telemetry-laravel
```

The service provider is auto-discovered. Set your DSN:

```dotenv
TIDEN_DSN=http://<publicKey>@<host:ingestPort>/<projectId>
TIDEN_RELEASE=my-app@1.2.3
```

That's it — reported exceptions now appear in your Tiden project. `environment`
defaults to `APP_ENV`.

## Configuration (optional)

```bash
php artisan vendor:publish --tag=tiden-config
```

`config/tiden.php`:

| Key | Env | Default | Description |
|---|---|---|---|
| `dsn` | `TIDEN_DSN` | — | Project DSN. No DSN → the integration is inert. |
| `release` | `TIDEN_RELEASE` | — | App version. |
| `environment` | `TIDEN_ENVIRONMENT` | `APP_ENV` | Deployment environment. |
| `send_default_pii` | `TIDEN_SEND_DEFAULT_PII` | `false` | Send likely-PII (off by default; PII is scrubbed). |
| `http_timeout` | `TIDEN_HTTP_TIMEOUT` | `5.0` in the console, `2.0` on web requests | Seconds one synchronous send may take. |
| `max_breadcrumbs` | `TIDEN_MAX_BREADCRUMBS` | `100` | Breadcrumbs kept per event; the oldest are dropped first. |
| `before_send` | — | `null` | Hook to change or drop an event. See [before_send](#before_send). |
| `reset_scope` | `TIDEN_RESET_SCOPE` | `true` | Fresh breadcrumbs per queue job and top-level command. See [Scope per job and command](#scope-per-job-and-command). |
| `breadcrumbs.sql` | `TIDEN_BREADCRUMBS_SQL` | `true` | Record SQL statements (never their bindings). |
| `breadcrumbs.queue` | `TIDEN_BREADCRUMBS_QUEUE` | `true` | Record queue job processing, completion and failure. |
| `breadcrumbs.logs` | `TIDEN_BREADCRUMBS_LOGS` | `true` | Record log messages (never their context). |
| `breadcrumbs.max_message_length` | `TIDEN_BREADCRUMBS_MAX_MESSAGE_LENGTH` | `1024` | Cut SQL and log breadcrumb messages to this many bytes, on a UTF-8 character boundary. `0` = no limit. |

### before_send

`before_send` receives the event array and returns it (changed or not), or
`null` to drop the event. Give it in one of two forms, because a closure in a
config file breaks `php artisan config:cache`:

```php
// A static method.
'before_send' => [App\Tiden\Filter::class, 'beforeSend'],

// The class name of an invokable class. The container builds it.
'before_send' => App\Tiden\Filter::class,
```

The integration ignores a closure or any other value.

## Scope per job and command

A queue worker is one long PHP process, so the SDK scope (breadcrumbs, tags,
user, extra) would collect data from every job it runs. With `reset_scope` on
(the default):

- Each queue job starts with no breadcrumbs. Tags, user and extra that you set
  before the job are kept. Tags that the job sets are discarded when the job
  completes.
- When a job throws, its breadcrumbs stay on the scope until the worker has
  reported the exception. The next job then discards them.
- Jobs on the `sync` connection (and `deferred` / `background`) run in the
  caller's request or command and keep its scope.
- Each top-level Artisan command starts with no breadcrumbs and sets the tag
  `command` to the command name. A command called from inside another command
  (`Artisan::call`) keeps the breadcrumbs of the outer command.

Set `TIDEN_RESET_SCOPE=false` to keep one scope for the whole process.

## Transport failures

Sends are synchronous and never throw. When a send fails, the integration
writes one `debug` log record, `tiden.transport.send.failed`, with the reason,
the HTTP status, the envelope size in bytes and the curl error number. The
record never contains the ingest URL (it includes the DSN key) or the event
payload. If logging that record causes another failed send, the second failure
is not logged, so the integration cannot loop.

## Manual capture

```php
use Tiden\Sdk;

Sdk::captureException($e);
Sdk::captureMessage('checkout completed', 'info');
```

## License

[MIT](LICENSE)
