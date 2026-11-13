<?php

declare(strict_types=1);

namespace Procteye;

use Generator;
use Procteye\Enum\AssuranceLevel;
use Procteye\Enum\EvidenceMode;

/**
 * Operations on integrity sessions: the core resource of the API.
 *
 * Accessed via {@see Client::$sessions}.
 */
final class Sessions
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Create a new integrity session. POST /external/v1/sessions.
     *
     * A fresh Idempotency-Key is sent automatically; pass `idempotency_key` in
     * $params to make retries return the original session.
     *
     * @param array{
     *     assurance_level?:string|AssuranceLevel,
     *     duration_minutes?:int,
     *     external_user_ref?:string,
     *     candidate_name?:string,
     *     candidate_email?:string,
     *     evidence_mode?:string|EvidenceMode,
     *     metadata?:array<string,mixed>,
     *     idempotency_key?:string
     * } $params
     *
     * @return Session The newly created session.
     *
     * @throws \Procteye\Exception\ProcteyeException on any non-2xx response
     *         (e.g. AuthenticationException for a missing or invalid API key)
     *         or transport failure.
     */
    public function create(array $params = []): Session
    {
        $idempotencyKey = $params['idempotency_key'] ?? null;
        unset($params['idempotency_key']);

        $body = [];
        foreach (['assurance_level', 'duration_minutes', 'external_user_ref', 'candidate_name', 'candidate_email', 'evidence_mode', 'metadata'] as $field) {
            if (array_key_exists($field, $params) && $params[$field] !== null) {
                $body[$field] = $this->normalize($params[$field]);
            }
        }
        // metadata is a JSON object on the wire. PHP encodes an empty array as
        // [] and a list as an array, so always send it as an object.
        if (isset($body['metadata']) && is_array($body['metadata'])) {
            $body['metadata'] = (object) $body['metadata'];
        }

        $data = $this->client->request('POST', '/external/v1/sessions', [
            'body' => $body,
            'idempotency_key' => $idempotencyKey,
        ]);

        return new Session($data ?? []);
    }

    /**
     * Retrieve a single session by id. GET /external/v1/sessions/{id}.
     *
     * @param string $sessionId Id of the session to fetch.
     *
     * @return Session
     *
     * @throws \Procteye\Exception\ProcteyeException on any non-2xx response
     *         (e.g. InvalidRequestException when the id does not exist, or
     *         AuthenticationException for a missing or invalid API key) or
     *         transport failure.
     */
    public function retrieve(string $sessionId): Session
    {
        $data = $this->client->request('GET', '/external/v1/sessions/' . self::encode($sessionId));
        return new Session($data ?? []);
    }

    /**
     * Fetch a single page of sessions. GET /external/v1/sessions.
     *
     * Returns only the sessions. To know whether another page exists, use
     * {@see listPage()}, which also carries `has_more`. Pass `starting_after`
     * with a session id to get the sessions created before it (the list is
     * newest first).
     *
     * @param array{limit?:int,starting_after?:string} $params
     *
     * @return list<Session>
     *
     * @throws \Procteye\Exception\ProcteyeException on any non-2xx response
     *         (e.g. AuthenticationException for a missing or invalid API key)
     *         or transport failure.
     */
    public function list(array $params = []): array
    {
        $data = $this->listRaw($params);
        $rows = (is_array($data) && isset($data['data']) && is_array($data['data'])) ? $data['data'] : [];

        $sessions = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $sessions[] = new Session($row);
            }
        }
        return $sessions;
    }

    /**
     * Fetch a single page of sessions together with `has_more`.
     * GET /external/v1/sessions.
     *
     * Pass `starting_after` with a session id to get the sessions created
     * before it (the list is newest first). While `$page->hasMore` is true,
     * pass `$page->lastId()` as `starting_after` to fetch the next page.
     *
     * @param array{limit?:int,starting_after?:string} $params
     *
     * @throws \Procteye\Exception\ProcteyeException on any non-2xx response
     *         (e.g. AuthenticationException for a missing or invalid API key)
     *         or transport failure.
     */
    public function listPage(array $params = []): SessionList
    {
        return SessionList::fromArray($this->listRaw($params));
    }

    /**
     * Fetch a single raw page (the list envelope: {object, data, has_more?}).
     *
     * @param array{limit?:int,starting_after?:string} $params
     *
     * @return array<string,mixed>|null
     *
     * @throws \Procteye\Exception\ProcteyeException on any non-2xx response
     *         (e.g. AuthenticationException for a missing or invalid API key)
     *         or transport failure.
     */
    public function listRaw(array $params = []): ?array
    {
        return $this->client->request('GET', '/external/v1/sessions', [
            'query' => [
                'limit' => $params['limit'] ?? null,
                'starting_after' => $params['starting_after'] ?? null,
            ],
        ]);
    }

    /**
     * Auto-paginating iterator over sessions.
     *
     * Transparently fetches subsequent pages using the last session id as the
     * server-side `starting_after` cursor. Stops
     * when the API signals `has_more: false` or a page returns fewer rows than
     * the requested limit.
     *
     * @param array{limit?:int,starting_after?:string} $params
     *
     * @return Generator<int, Session>
     *
     * @throws \InvalidArgumentException when `limit` is outside the API's
     *         accepted page-size range of 1 to 100 (see the `limit` query
     *         parameter on GET /external/v1/sessions).
     * @throws \Procteye\Exception\ProcteyeException from the underlying page
     *         fetch (e.g. AuthenticationException for a missing or invalid
     *         API key) or transport failure; raised lazily as the generator
     *         is iterated, not when it is created.
     */
    public function autoPagingIterator(array $params = []): Generator
    {
        $limit = $params['limit'] ?? 20;
        if (!is_int($limit) || $limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('limit must be an integer between 1 and 100.');
        }
        $cursor = $params['starting_after'] ?? null;
        $previousFirstId = null;

        while (true) {
            $page = $this->listRaw(['limit' => $limit, 'starting_after' => $cursor]);
            $rows = (is_array($page) && isset($page['data']) && is_array($page['data'])) ? $page['data'] : [];

            if ($rows === []) {
                return;
            }

            $hasMore = is_array($page) ? ($page['has_more'] ?? null) : null;
            $first = $rows[0];
            $firstId = (is_array($first) && isset($first['id']) && is_string($first['id'])) ? $first['id'] : null;
            // A server that predates starting_after support ignores the
            // cursor and always returns the newest `limit` rows with no
            // has_more field. For an org with more than `limit` sessions
            // that page is never shorter than `limit`, so the "fewer rows
            // than limit" stop below never fires either. Detect the repeat
            // before yielding it so we never spin on page 1 forever or
            // re-yield the same rows.
            if ($hasMore === null && $previousFirstId !== null && $firstId === $previousFirstId) {
                return;
            }
            $previousFirstId = $firstId;

            foreach ($rows as $row) {
                if (is_array($row)) {
                    yield new Session($row);
                }
            }

            if ($hasMore === false) {
                return;
            }
            if ($hasMore === null && count($rows) < $limit) {
                return;
            }

            $last = end($rows);
            $cursor = (is_array($last) && isset($last['id']) && is_string($last['id'])) ? $last['id'] : null;
            if ($cursor === null) {
                return;
            }
        }
    }

    /**
     * Mark a session complete (no further violations accepted). POST .../complete.
     *
     * @param string      $sessionId       Id of the session to complete.
     * @param string|null $idempotencyKey  Overrides the auto-generated Idempotency-Key.
     *
     * @return Session
     *
     * @throws \Procteye\Exception\ProcteyeException on any non-2xx response
     *         (e.g. AuthenticationException for a missing or invalid API key)
     *         or transport failure.
     */
    public function complete(string $sessionId, ?string $idempotencyKey = null): Session
    {
        $data = $this->client->request('POST', '/external/v1/sessions/' . self::encode($sessionId) . '/complete', [
            'body' => [],
            'idempotency_key' => $idempotencyKey,
        ]);
        return new Session($data ?? []);
    }

    /**
     * Cancel a session before it completes. POST .../cancel.
     *
     * A successful cancel sends the `session.canceled` webhook. A session that
     * is already final (completed, canceled or expired) raises an
     * InvalidRequestException with HTTP 409 and code `session_not_active`;
     * that 409 is not retried.
     *
     * @param string      $sessionId       Id of the session to cancel.
     * @param string|null $idempotencyKey  Overrides the auto-generated Idempotency-Key.
     *
     * @return Session
     *
     * @throws \Procteye\Exception\ProcteyeException on any non-2xx response
     *         (e.g. AuthenticationException for a missing or invalid API key)
     *         or transport failure.
     */
    public function cancel(string $sessionId, ?string $idempotencyKey = null): Session
    {
        $data = $this->client->request('POST', '/external/v1/sessions/' . self::encode($sessionId) . '/cancel', [
            'body' => [],
            'idempotency_key' => $idempotencyKey,
        ]);
        return new Session($data ?? []);
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof AssuranceLevel || $value instanceof EvidenceMode) {
            return $value->value;
        }
        return $value;
    }

    private static function encode(string $value): string
    {
        return rawurlencode($value);
    }
}
