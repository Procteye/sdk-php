# procteye (PHP)

Official **server-side** PHP SDK for the [Procteye](https://procteye.com) Integrity API. Use it from your backend to create and manage integrity sessions and to verify incoming webhooks.

> This is a secret-key SDK. Never ship your `pe_sk_...` key to a browser or mobile client.

## Install

```bash
composer require procteye/procteye
```

Requires PHP 8.1+ and `ext-curl`. No third-party runtime dependencies (uses only the standard library).

## Quickstart

```php
require 'vendor/autoload.php';

use Procteye\Client;

$client = new Client(getenv('PROCTEYE_API_KEY'));

$session = $client->sessions->create([
    'assurance_level'  => 'HIGH',
    'external_user_ref' => 'user_42',
    'candidate_email'  => 'candidate@example.com',
]);

// Hand $session->getClientSecret() to the browser to launch the session.
echo $session->getId(), ' ', $session->getClientSecret(), PHP_EOL;
```

## Configuration

```php
$client = new Procteye\Client(
    'pe_sk_live_...',              // or the PROCTEYE_API_KEY env var
    [
        'base_url'    => 'https://api.procteye.com', // or PROCTEYE_BASE_URL
        'timeout'     => 30,   // per-request timeout in seconds
        'max_retries' => 2,    // retries on 429/5xx
    ]
);
```

## Sessions

```php
$client->sessions->create(['assurance_level' => 'HIGH']); // POST /external/v1/sessions
$client->sessions->retrieve($sessionId);                  // GET  /external/v1/sessions/{id}
$client->sessions->complete($sessionId);                  // POST .../complete
$client->sessions->cancel($sessionId);                    // POST .../cancel

// A single page (returns Session[]):
foreach ($client->sessions->list(['limit' => 50]) as $session) {
    echo $session->getId(), ' ', $session->getStatus(), PHP_EOL;
}
```

Sessions are returned as `Procteye\Session` objects: use the typed getters
(`$session->getStatus()`, `$session->getVerdict()`, …) or array access on the
raw payload (`$session['client_secret']`). `$session->toArray()` returns the
full decoded payload.

Enums are available for convenience and accepted anywhere a string is:

```php
use Procteye\Enum\AssuranceLevel;
use Procteye\Enum\EvidenceMode;

$client->sessions->create([
    'assurance_level' => AssuranceLevel::HIGH,
    'evidence_mode'   => EvidenceMode::THUMBNAILS,
]);
```

### Pagination

The API paginates with a server-side cursor. Sessions come back newest first
(by creation time, then id), up to 100 per page. Pass `starting_after` with a
session id to get the sessions created before it; `has_more` is true while
more pages exist. An id that is malformed or not visible to your key returns a
400. `autoPagingIterator()` follows the cursor for you, passing the last
session id of each page as `starting_after`:

```php
foreach ($client->sessions->autoPagingIterator(['limit' => 50]) as $session) {
    // Iterates across all pages; fetches the next page lazily.
    echo $session->getId(), PHP_EOL;
}
```

To fetch one page at a time and read `has_more`, use `listPage()`. It returns
a `Procteye\SessionList` with `data` (the sessions), `hasMore` and `lastId()`,
the cursor for the next page. `list()` still returns a plain array of sessions:

```php
$page = $client->sessions->listPage(['limit' => 50]);
foreach ($page as $session) {
    echo $session->getId(), PHP_EOL;
}
if ($page->hasMore) {
    $next = $client->sessions->listPage(['limit' => 50, 'starting_after' => $page->lastId()]);
}
```

## Idempotency

Every write automatically carries an `Idempotency-Key` header (a UUIDv4 per call). Pass your own to make a retry safe end-to-end:

```php
$client->sessions->create(['assurance_level' => 'HIGH', 'idempotency_key' => 'order_1234']);
$client->sessions->complete($sessionId, 'complete_order_1234');
```

Because writes are idempotent, the SDK safely **retries** on `429`/`5xx` (and transport errors) with exponential backoff and full jitter, honoring `Retry-After`. A `409` with code `idempotency_request_in_progress` (the first request with the same key is still running) is retried the same way, with the same `Idempotency-Key`, and counts toward `max_retries`. Any other `409`, such as `idempotency_key_in_use`, is not retried.

## Errors

All non-2xx responses throw a typed exception under `Procteye\Exception\`,
exposing `getCode()` (the string error code), `getMessage()`, `getHttpStatus()`,
`getRequestId()`, `getErrorType()`, and `getDocUrl()`.

```php
use Procteye\Exception\BillingException;
use Procteye\Exception\RateLimitException;
use Procteye\Exception\InvalidRequestException;

try {
    $client->sessions->create(['assurance_level' => 'HIGH']);
} catch (BillingException $e) {
    if ($e->getCode() === 'insufficient_credits') {
        // top up
    }
} catch (RateLimitException $e) {
    // back off
} catch (InvalidRequestException $e) {
    error_log($e->getMessage() . ' request_id=' . $e->getRequestId());
}
```

| HTTP status | Class | Retried |
| --- | --- | --- |
| 400 / 404 / 422 | `InvalidRequestException` | no |
| 401 | `AuthenticationException` | no |
| 402 | `BillingException` | no |
| 403 | `PermissionException` (extends `AuthenticationException`; code `insufficient_scope`) | no |
| 409 | `InvalidRequestException` (code `idempotency_request_in_progress`, `idempotency_key_in_use`, or `session_not_active` from cancel on a session that is already final) | only `idempotency_request_in_progress` |
| 429 | `RateLimitException` | yes |
| 5xx | `ApiException` | yes |
| other 4xx | `InvalidRequestException` | no |
| network / timeout | `ConnectionException` | yes |

All extend `Procteye\Exception\ProcteyeException`.

## Webhook verification

Verify webhook signatures with the **raw** request body ([Standard Webhooks](https://www.standardwebhooks.com) compatible):

```php
use Procteye\Webhooks;
use Procteye\Exception\SignatureVerificationException;

$payload = file_get_contents('php://input'); // raw body, NOT a parsed array
$secret  = getenv('PROCTEYE_WEBHOOK_SECRET'); // whsec_...

try {
    $event = Webhooks::constructEvent($payload, getallheaders(), $secret);
} catch (SignatureVerificationException $e) {
    http_response_code(400);
    exit;
}

if (($event['type'] ?? null) === 'session.completed') {
    // The runtime session ended; finalize the verdict.
    $client->sessions->complete($event['data']['id']);
} elseif (($event['type'] ?? null) === 'session.canceled') {
    // Sent after a successful cancel.
    error_log('Session canceled: ' . $event['data']['id']);
}
http_response_code(200);
```

The verifier rejects any request with a missing header, a bad signature, or a
timestamp older than 5 minutes (configurable via the `$tolerance` argument).


## Links

- Documentation: https://docs.procteye.com
- Packagist: https://packagist.org/packages/procteye/procteye
- Source: https://github.com/Procteye/sdk-php

## License

MIT
