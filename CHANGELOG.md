# Changelog

## 2.1.0 (2026-11-13)

- Document the `session.canceled` webhook, sent after a successful cancel, and add a signed test vector for it. Document that canceling a session that is already final returns 409 `session_not_active` as an invalid request error, which is not retried.
- Add `Sessions::listPage()`, which returns a `SessionList` page with `data`, `hasMore` and `lastId()`, so a single page exposes `has_more`. `list()` still returns a plain array.
- Encode `metadata` as a JSON object, so an empty `metadata` sends `{}` instead of `[]`. Bodiless writes (`complete`, `cancel`) and an empty create now send `{}`.
- Map HTTP 403 (`insufficient_scope`) to the new `PermissionException`, which extends `AuthenticationException`, instead of `InvalidRequestException`.
- Retry a 409 `idempotency_request_in_progress` with the same `Idempotency-Key` and the usual backoff, counting toward `max_retries`. Other 409s are not retried.
- Add `.gitattributes` so the Packagist archive leaves out `tests/`, `examples/` and `phpunit.xml`.
- README: describe the `starting_after` cursor correctly and document which HTTP status maps to which error class.
- Point the package homepage and source at the public mirror https://github.com/procteye/sdk-php.
