# Didit Automated Verification Integration

The **Advanced Client Verification** module integrates with [Didit](https://didit.me) as an automated biometric KYC provider via the `DiditProvider` adapter implementing `KycProviderInterface`.

The core architecture decouples the verification engine from specific vendor APIs, ensuring security, maintainability, and clean extensibility.

---

## 1. Verification Flow Lifecycle

```
[WHMCS Client Area]
        │ 1. User clicks "Verify with Didit"
        ▼
[DiditProvider::createSession()]
        │ 2. Generates vendor_data payload: "CV-{verification_id}-{client_id}"
        │ 3. Issues POST request to Didit API
        ▼
[Didit API]
        │ 4. Returns session_id & verification URL
        ▼
[Client Web Browser]
        │ 5. Client redirected to Didit portal (completes ID scan + biometric selfie)
        ▼
[Didit Identity Core]
        │ 6. Analyzes document validity, liveness & facial match
        │ 7. Fires HMAC-signed webhook to WHMCS
        ▼
[api/webhook.php → DiditWebhookHandler]
        │ 8. Validates HMAC signature & timestamp (5-min tolerance)
        │ 9. Enforces client ownership IDOR check from vendor_data
        │ 10. Checks idempotency in mod_cv_webhook_events
        ▼
[HybridVerificationService & RiskEngine]
        │ 11. Applies risk score evaluation & decision tree
        │ 12. Updates verification status (Approved / Review Required / Rejected)
        ▼
[WHMCS Core]
        │ 13. Dispatches native notification email & unblocks Checkout Guard
```

---

## 2. Security & Anti-Fraud Mechanisms

### A. Strict Client Mapping & IDOR Protection
When initiating a session, the module injects an immutable reference into the Didit `vendor_data` parameter:
```
CV-{verification_id}-{client_id} (e.g. CV-10042-501)
```
Upon webhook callback, the handler unpacks this token and cross-references:
1. `verification_id` exists in `mod_cv_verifications`.
2. `client_id` strictly matches the verification record's assigned client.
3. The session ID matches the recorded external session ID.

This prevents cross-account token substitution or callback tampering attacks.

### B. Inbound Webhook Verification
Inbound webhooks to `api/webhook.php` must include the `Didit-Signature` header in standard HMAC format:
```
Didit-Signature: t=1756001234,v1=9f83c1e2d...
```

The validation pipeline performs:
1. **Timestamp Check**: Rejects payloads where `|current_time - t| > 300` seconds to eliminate replay attacks.
2. **Signature Computation**:
   ```php
   $signedPayload = $timestamp . '.' . $rawBody;
   $expectedSignature = hash_hmac('sha256', $signedPayload, $webhookSecret);
   ```
3. **Constant-Time Comparison**: Evaluated using `hash_equals()` to prevent timing attack vulnerabilities.
4. **Idempotency Check**: Event IDs are registered in `mod_cv_webhook_events`. Duplicated webhook deliveries return an immediate `200 OK (Already Processed)`.

### C. Fail-Safe Error Handling
If the Didit API experiences an outage, network timeout, or invalid response:
- The verification state transitions to `review_required` (Manual Review).
- **The system will NEVER fail open or auto-approve an error state.**

---

## 3. Configuration Parameters

In **WHMCS Admin** → **Advanced Client Verification** → **Settings** → **Didit Provider**:

| Field | Description |
|-------|-------------|
| **API Key** | Your Didit Secret Key (Stored encrypted in the database). |
| **Workflow ID** | The UUID of your configured Didit verification workflow. |
| **Webhook Secret** | Secret used for verifying webhook HMAC signatures. |
| **Auto-Approve** | When enabled, approved Didit results with low risk scores will automatically grant `approved` status without manual admin intervention. |
| **On Error Action** | Default action on provider connection timeout. Recommended: `Manual Review`. |

---

## 4. Webhook URL

Configure this URL in your [Didit Developer Console](https://didit.me):

```
https://yourdomain.com/modules/addons/clientverification/api/webhook.php
```

Ensure your server allows inbound HTTP POST traffic with `application/json` payload to this path.
