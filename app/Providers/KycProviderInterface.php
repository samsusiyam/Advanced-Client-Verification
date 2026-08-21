<?php

namespace ClientVerification\Providers;

/**
 * Data transfer object representing a verification session returned by a provider.
 */
class KycSession
{
    public string $sessionId;
    public string $redirectUrl;
    public array $metadata = [];

    public function __construct(string $sessionId, string $redirectUrl, array $metadata = [])
    {
        $this->sessionId = $sessionId;
        $this->redirectUrl = $redirectUrl;
        $this->metadata = $metadata;
    }
}

/**
 * Data transfer object representing a provider result.
 */
class KycResult
{
    public const DECISION_APPROVED = 'approved';
    public const DECISION_DECLINED = 'declined';
    public const DECISION_REVIEW = 'review';
    public const DECISION_ERROR = 'error';

    public string $sessionId;
    public string $status;
    public string $decision;
    public float $riskScore = 0;
    public string $riskLevel = 'low';
    public array $rawData = [];

    public function __construct(
        string $sessionId,
        string $status,
        string $decision,
        float $riskScore = 0,
        string $riskLevel = 'low',
        array $rawData = []
    ) {
        $this->sessionId = $sessionId;
        $this->status = $status;
        $this->decision = $decision;
        $this->riskScore = $riskScore;
        $this->riskLevel = $riskLevel;
        $this->rawData = $rawData;
    }
}

/**
 * Verification entity passed into providers. Providers MUST NOT hardcode
 * behaviour outside of this contract so new providers can be added freely.
 */
class VerificationEntity
{
    public int $id;
    public int $clientId;
    public string $method;
    public string $clientReference;
    public array $personalData = [];

    public function __construct(int $id, int $clientId, string $method, string $clientReference, array $personalData = [])
    {
        $this->id = $id;
        $this->clientId = $clientId;
        $this->method = $method;
        $this->clientReference = $clientReference;
        $this->personalData = $personalData;
    }
}

interface KycProviderInterface
{
    /**
     * Create a verification session with the remote provider.
     */
    public function createSession(VerificationEntity $verification): KycSession;

    /**
     * Fetch the current status of a session.
     */
    public function getStatus(string $sessionId): KycResult;

    /**
     * Handle an inbound webhook payload and return a normalized result.
     */
    public function handleWebhook(array $payload, array $headers): KycResult;

    /**
     * Human-readable provider name.
     */
    public function getName(): string;
}
