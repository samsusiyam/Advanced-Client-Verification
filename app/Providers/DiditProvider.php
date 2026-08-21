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
        string $baseUrl = 'https://verification.didit.me'
    ) {
        $this->apiKey = trim($apiKey);
        $this->workflowId = trim($workflowId);
        $this->webhookSecret = trim($webhookSecret);
        $cleanedBase = rtrim(trim($baseUrl), '/');
        if (empty($cleanedBase) || strpos($cleanedBase, 'apikyc.didit.me') !== false) {
            $cleanedBase = 'https://verification.didit.me';
        }
        $this->baseUrl = $cleanedBase;
        $this->callbackUrl = trim($callbackUrl);
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
        ];

        if (!empty($this->callbackUrl)) {
            $payload['callback'] = $this->callbackUrl;
            $payload['callback_url'] = $this->callbackUrl;
        }

        $headers = [
            'x-api-key: ' . $this->apiKey,
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $targetUrl = $this->baseUrl . '/v3/session/';
        $response = Http::post($targetUrl, $payload, $headers);

        if (!$response['success'] && ($response['http_code'] === 404 || strpos($response['error'], '404') !== false)) {
            $targetUrl = $this->baseUrl . '/v2/session';
            $response = Http::post($targetUrl, $payload, $headers);
        }

        if (!$response['success']) {
            $errDetail = $response['error'] ?: 'HTTP ' . $response['http_code'];
            $resData = is_array($response['data']) ? $response['data'] : [];
            if (!empty($resData['message'])) {
                $errDetail .= ' - ' . (is_array($resData['message']) ? json_encode($resData['message']) : $resData['message']);
            } elseif (!empty($resData['detail'])) {
                $errDetail .= ' - ' . (is_array($resData['detail']) ? json_encode($resData['detail']) : $resData['detail']);
            } elseif (!empty($resData['error'])) {
                $errDetail .= ' - ' . (is_array($resData['error']) ? json_encode($resData['error']) : $resData['error']);
            }

            $diag = sprintf(
                '[Endpoint: %s | HTTP: %d | Error: %s]',
                $targetUrl,
                $response['http_code'],
                $errDetail
            );
            throw new \RuntimeException('Didit session creation failed: ' . $diag);
        }

        $data = $response['data'];
        $sessionId = $data['session_id'] ?? ($data['id'] ?? ($data['session_token'] ?? ''));
        $url = $data['url'] ?? ($data['session_url'] ?? ($data['sessionUrl'] ?? ($data['url_token'] ?? '')));

        if (!$sessionId && !$url) {
            throw new \RuntimeException('Didit session creation failed: missing session response data [URL/SessionID empty]');
        }

        return new KycSession($sessionId ?: 'didit_' . time(), $url, $data);
    }

    public function getStatus(string $sessionId): KycResult
    {
        $headers = [
            'x-api-key: ' . $this->apiKey,
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
        ];

        // 1. Try Didit v3 session decision endpoint
        $response = Http::get(
            $this->baseUrl . '/v3/session/' . rawurlencode($sessionId) . '/decision/',
            $headers
        );

        // 2. Try Didit v3 session endpoint
        if (!$response['success']) {
            $response = Http::get(
                $this->baseUrl . '/v3/session/' . rawurlencode($sessionId) . '/',
                $headers
            );
        }

        // 3. Try Didit v2 session endpoint
        if (!$response['success']) {
            $response = Http::get(
                $this->baseUrl . '/v2/session/' . rawurlencode($sessionId),
                $headers
            );
        }

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
     * Supports: X-Signature-V2 (canonical JSON), X-Signature (raw bytes),
     * X-Signature-Simple, and Didit-Signature timestamped signatures.
     */
    public function verifyWebhook(string $rawBody, string $sig, int $timestamp, array $payload = []): bool
    {
        if (empty($this->webhookSecret)) {
            return true;
        }

        $sig = trim($sig);
        if (empty($sig)) {
            return false;
        }

        // 1. Exact raw body HMAC (standard X-Signature-V2)
        $expectedRaw = hash_hmac('sha256', $rawBody, $this->webhookSecret);
        if (hash_equals($expectedRaw, $sig)) {
            return true;
        }

        // 2. Canonical JSON body HMAC (RFC 8785)
        if (!empty($payload)) {
            $canonicalJson = self::canonicalizeJson($payload);
            $expectedCanonical = hash_hmac('sha256', $canonicalJson, $this->webhookSecret);
            if (hash_equals($expectedCanonical, $sig)) {
                return true;
            }
        }

        // 3. Timestamped raw body HMAC: timestamp . '.' . rawBody
        if ($timestamp > 0) {
            $expectedTs = hash_hmac('sha256', $timestamp . '.' . $rawBody, $this->webhookSecret);
            if (hash_equals($expectedTs, $sig)) {
                return true;
            }
        }

        // 4. Hex vs Base64 encoding tolerance
        $expectedBase64 = base64_encode(hash_hmac('sha256', $rawBody, $this->webhookSecret, true));
        if (hash_equals($expectedBase64, $sig)) {
            return true;
        }

        // 5. Header format t=...,v2=... or t=...,v1=...
        if (strpos($sig, 't=') !== false && strpos($sig, '=') !== false) {
            $parts = [];
            foreach (explode(',', $sig) as $pair) {
                $kv = explode('=', $pair, 2);
                if (count($kv) === 2) {
                    $parts[trim($kv[0])] = trim($kv[1]);
                }
            }
            $t = isset($parts['t']) ? (int) $parts['t'] : $timestamp;
            $hash = $parts['v2'] ?? ($parts['v1'] ?? '');
            if (!empty($hash)) {
                if ($t && abs(time() - $t) > 300) {
                    return false;
                }
                $expected = hash_hmac('sha256', $rawBody, $this->webhookSecret);
                if (hash_equals($expected, $hash)) {
                    return true;
                }
                if ($t) {
                    $expectedWithT = hash_hmac('sha256', $t . '.' . $rawBody, $this->webhookSecret);
                    if (hash_equals($expectedWithT, $hash)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private static function canonicalizeJson(array $data): string
    {
        ksort($data);
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = (array_keys($v) !== range(0, count($v) - 1)) ? json_decode(self::canonicalizeJson($v), true) : $v;
            }
        }
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function normalize(array $data): KycResult
    {
        $sessionId = (string) ($data['session_id'] ?? ($data['id'] ?? ''));
        
        $rawStatus = '';
        if (isset($data['status']) && is_string($data['status'])) {
            $rawStatus = $data['status'];
        } elseif (isset($data['decision']) && is_string($data['decision'])) {
            $rawStatus = $data['decision'];
        } elseif (isset($data['decision']) && is_array($data['decision'])) {
            $rawStatus = (string) ($data['decision']['status'] ?? ($data['decision']['decision'] ?? 'pending'));
        } elseif (isset($data['session_status']) && is_string($data['session_status'])) {
            $rawStatus = $data['session_status'];
        }

        $statusLower = strtolower(trim((string) $rawStatus));

        $decision = KycResult::DECISION_REVIEW;
        switch ($statusLower) {
            case 'approved':
            case 'clear':
            case 'success':
            case 'passed':
            case 'verified':
                $decision = KycResult::DECISION_APPROVED;
                $statusLower = 'approved';
                break;
            case 'declined':
            case 'denied':
            case 'rejected':
            case 'failed':
                $decision = KycResult::DECISION_DECLINED;
                $statusLower = 'rejected';
                break;
            case 'in review':
            case 'in_review':
            case 'review':
            case 'pending':
            case 'in progress':
            case 'in_progress':
            case 'resubmitted':
            case 'not started':
                $decision = KycResult::DECISION_REVIEW;
                break;
            case 'expired':
            case 'kyc expired':
            case 'abandoned':
            case 'error':
            case 'timeout':
                $decision = KycResult::DECISION_ERROR;
                break;
            default:
                $decision = KycResult::DECISION_REVIEW;
        }

        $riskScore = (float) ($data['risk_score'] ?? ($data['score'] ?? ($data['decision']['risk_score'] ?? 0)));
        $riskLevel = $this->levelFromScore($riskScore);

        return new KycResult($sessionId, $statusLower, $decision, $riskScore, $riskLevel, $data);
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
