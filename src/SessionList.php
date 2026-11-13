<?php

declare(strict_types=1);

namespace Procteye;

/**
 * One page of the sessions list endpoint, returned by
 * {@see Sessions::listPage()}.
 *
 * `$data` holds the sessions on this page (newest first) and `$hasMore` tells
 * whether another page exists. To fetch it, pass {@see lastId()} as
 * `starting_after`.
 *
 * @implements \IteratorAggregate<int, Session>
 */
final class SessionList implements \IteratorAggregate, \Countable
{
    /**
     * @param list<Session> $data
     */
    public function __construct(
        public readonly array $data,
        public readonly bool $hasMore,
    ) {
    }

    /**
     * Build a page from the raw list envelope ({object, data, has_more}).
     *
     * @param array<string, mixed>|null $envelope
     */
    public static function fromArray(?array $envelope): self
    {
        $rows = (is_array($envelope) && isset($envelope['data']) && is_array($envelope['data'])) ? $envelope['data'] : [];
        $sessions = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $sessions[] = new Session($row);
            }
        }
        $hasMore = is_array($envelope) && ($envelope['has_more'] ?? false) === true;
        return new self($sessions, $hasMore);
    }

    /** The id of the last session on this page, the cursor for the next page. */
    public function lastId(): ?string
    {
        if ($this->data === []) {
            return null;
        }
        return $this->data[count($this->data) - 1]->getId();
    }

    /** @return \ArrayIterator<int, Session> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->data);
    }

    public function count(): int
    {
        return count($this->data);
    }
}
