<?php

declare(strict_types=1);

namespace Procteye;

/**
 * An integrity session returned by create/retrieve/complete/cancel/list.
 *
 * Backs the raw API payload while offering typed getters for the documented
 * fields. Also implements {@see \ArrayAccess} so the raw shape stays available
 * (e.g. `$session['client_secret']`) and forward-compatible with new fields the
 * API may add before this SDK is updated.
 *
 * @implements \ArrayAccess<string, mixed>
 */
final class Session implements \ArrayAccess, \JsonSerializable
{
    /** @var array<string, mixed> */
    private array $data;

    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function getId(): ?string
    {
        return $this->stringOrNull('id');
    }

    public function getObject(): ?string
    {
        return $this->stringOrNull('object');
    }

    public function getStatus(): ?string
    {
        return $this->stringOrNull('status');
    }

    /** Present on create: the assurance level requested. */
    public function getAssuranceLevel(): ?string
    {
        return $this->stringOrNull('assurance_level');
    }

    /** Present on retrieve/list: the assurance level requested. */
    public function getAssuranceRequested(): ?string
    {
        return $this->stringOrNull('assurance_requested');
    }

    /** Present on retrieve/list once the session has progressed. */
    public function getAssuranceAchieved(): ?string
    {
        return $this->stringOrNull('assurance_achieved');
    }

    /** One-time secret handed to the browser client to start a session. */
    public function getClientSecret(): ?string
    {
        return $this->stringOrNull('client_secret');
    }

    public function getRiskScore(): int|float|null
    {
        $value = $this->data['risk_score'] ?? null;
        return is_int($value) || is_float($value) ? $value : null;
    }

    public function getCoverage(): int|float|null
    {
        $value = $this->data['coverage'] ?? null;
        return is_int($value) || is_float($value) ? $value : null;
    }

    /** One of clean|flagged|removed|inconclusive, or null. */
    public function getVerdict(): ?string
    {
        return $this->stringOrNull('verdict');
    }

    public function getViolationCount(): ?int
    {
        $value = $this->data['violation_count'] ?? null;
        return is_int($value) ? $value : null;
    }

    /**
     * Lowest enforcement tier observed during the session
     * (`minimal` < `reduced` < `full`); never rises back up once lowered, or
     * null.
     */
    public function getEnforcementTier(): ?string
    {
        return $this->stringOrNull('enforcement_tier');
    }

    /**
     * Latched `true` the first time the candidate's paired mobile observer
     * device disconnects during the session; never un-latches. Null when
     * absent from the payload.
     */
    public function getMobileDisconnected(): ?bool
    {
        $value = $this->data['mobile_disconnected'] ?? null;
        return is_bool($value) ? $value : null;
    }

    public function getExternalUserRef(): ?string
    {
        return $this->stringOrNull('external_user_ref');
    }

    public function getEvidenceMode(): ?string
    {
        return $this->stringOrNull('evidence_mode');
    }

    public function getLivemode(): ?bool
    {
        $value = $this->data['livemode'] ?? null;
        return is_bool($value) ? $value : null;
    }

    /** The launch descriptor {mode, deep_link?, download_url?, mobile_pairing_url?} (present on create). */
    public function getLaunch(): ?array
    {
        $value = $this->data['launch'] ?? null;
        return is_array($value) ? $value : null;
    }

    public function getExpiresAt(): ?string
    {
        return $this->stringOrNull('expires_at');
    }

    public function getCreatedAt(): ?string
    {
        return $this->stringOrNull('created_at');
    }

    public function getCompletedAt(): ?string
    {
        return $this->stringOrNull('completed_at');
    }

    /** @return array<string, mixed>|null */
    public function getMetadata(): ?array
    {
        $value = $this->data['metadata'] ?? null;
        return is_array($value) ? $value : null;
    }

    /**
     * The raw, decoded payload exactly as returned by the API.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->data;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->data[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->data[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('Session objects are read-only.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('Session objects are read-only.');
    }

    private function stringOrNull(string $key): ?string
    {
        $value = $this->data[$key] ?? null;
        return is_string($value) ? $value : null;
    }
}
