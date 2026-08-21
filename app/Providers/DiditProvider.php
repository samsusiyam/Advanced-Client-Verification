<?php

namespace ClientVerification\Providers;

use ClientVerification\Helpers\Http;

/**
 * Didit automated KYC provider.
 *
 * Implements the KycProviderInterface contract so the core engine never
 * references Didit directly (future providers: Sumsub, Veriff, Persona, etc).
 */
class DiditProvider implements KycProviderInterface
{
    private string $apiKey;
    private string $workflowId;
    private string $webhookSecret;
    private string $baseUrl;
    private string $callbackUrl;

    public function __construct(
        string $apiKey,
        string $workflowId,
        string $webhookSecret,
        string $callbackUrl = '',
        string $baseUrl = 'https://apikyc.didit.me'
    ) {
        $this->apiKey = $apiKey;
        $this->workflowId = $workflowId;
        $this->webhookSecret = $webhookSecret;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->callbackUrl = $callbackUrl;
    }

    public function getName(): string
    {
        return 'Didit';
    }

    public function createSession(VerificationEntity $verification): KycSession
    {
        $payload = [
            'workflow_id' => $this->workflowId,
            'vendor_data' => $verification->clientReference,
            'callback' => $this->callbackUrl,
        ];

        $response = Http::post(
            $this->baseUrl . '/v2/session',
            $payload,
            [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ]
        );

        if (!$response['success']) {
            throw new \RuntimeException('Didit session creation failed: ' . $response['error']);
        }

        $data = $response['data'];
        $sessionId = $data['session_id'] ?? ($data['id'] ?? '');
        $url = $data['url'] ?? ($data['session_url'] ?? ($data['sessionUrl'] ?? ''));

        if (!$sessionId) {
            throw new \RuntimeException('Didit session creation failed: missing session id');
        }

        return new KycSession($sessionId, $url, $data);
    }

    public function getStatus(string $sessionId): KycResult
    {
        $response = Http::get(
            $this->baseUrl . '/v2/session/' . rawurlencode($sessionId),
            [
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: application/json',
            ]
        );

        if (!$response['success']) {
            return new KycResult($sessionId, 'error', KycResult::DECISION_ERROR, 0, 'low', ['error' => $response['error']]);
        }

        return $this->normalize($response['data']);
    }

    public function handleWebhook(array $payload, array $headers): KycResult
    {
        if (!isset($payload['session_id'])) {
            return new KycResult('', 'error', KycResult::DECISION_ERROR, 0, 'low', ['reason' => 'missing_session_id']);
        }

        return $this->normalize($payload);
    }

    /**
     * Verify the Didit webhook signature across all Didit header variants.
     * Supports: X-Signature, X-Signature-V2, X-Signature-Simple, and Didit-Signature
     */
    public function verifyWebhook(string $rawBody, $signatureHeader, ?int $timestamp = null): bool
    {
        if (empty($this->webhookSecret) || empty($signatureHeader)) {
            return false;
        }

        $sig = is_array($signatureHeader) ? ($signatureHeader[0] ?? '') : (string) $signatureHeader;
        $sig = trim($sig);

        // Check if header is in t=...,v1=... format
        if (strpos($sig, 't=') !== false && strpos($sig, '=') !== false) {
            $parts = [];
            foreach (explode(',', $sig) as $pair) {
                $kv = explode('=', $pair, 2);
                if (count($kv) === 2) {
                    $parts[trim($kv[0])] = trim($kv[1]);
                }
            }
            if (isset($parts['t'])) {
                $timestamp = (int) $parts['t'];
            }
            $hash = $parts['v2'] ?? ($parts['v1'] ?? '');
            if (!empty($hash)) {
                $sig = $hash;
            }
        }

        // Direct payload HMAC comparison
        $expectedDirect = hash_hmac('sha256', $rawBody, $this->webhookSecret);
        if (hash_equals($expectedDirect, $sig)) {
            return true;
        }

        // Timestamped payload HMAC comparison: timestamp.rawBody
        if ($timestamp !== null && $timestamp > 0) {
            $expectedWithTs = hash_hmac('sha256', $timestamp . '.' . $rawBody, $this->webhookSecret);
            if (hash_equals($expectedWithTs, $sig)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(array $data): KycResult
    {
        $sessionId = $data['session_id'] ?? '';
        $status = strtolower($data['status'] ?? ($data['decision'] ?? 'pending'));

        $decision = KycResult::DECISION_REVIEW;
        switch ($status) {
            case 'approved':
            case 'clear':
            case 'success':
                $decision = KycResult::DECISION_APPROVED;
                break;
            case 'denied':
            case 'declined':
            case 'rejected':
                $decision = KycResult::DECISION_DECLINED;
                break;
            case 'error':
            case 'failed':
            case 'timeout':
                $decision = KycResult::DECISION_ERROR;
                break;
            default:
                $decision = KycResult::DECISION_REVIEW;
        }

        $riskScore = (float) ($data['risk_score'] ?? ($data['score'] ?? 0));
        $riskLevel = $this->levelFromScore($riskScore);

        return new KycResult($sessionId, $status, $decision, $riskScore, $riskLevel, $data);
    }

    private function levelFromScore(float $score): string
    {
        if ($score >= 70) {
            return 'high';
        }
        if ($score >= 30) {
            return 'medium';
        }
        return 'low';
    }
}
