# Security & Compliance Specification

The **Advanced Client Verification** module is architected with a defense-in-depth security model specifically tailored for KYC and sensitive identity document management.

---

## 1. Threat Mitigation Matrix

| Threat Category | OWASP Vector | Mitigation Architecture |
|-----------------|--------------|--------------------------|
| **SQL Injection (SQLi)** | A03:2021-Injection | 100% of database interactions execute via Laravel/Illuminate Capsule with strict parameter binding. Raw concatenations are disallowed. |
| **Cross-Site Scripting (XSS)** | A03:2021-Injection | Context-aware escaping using `Sanitizer::escape()` with `ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5`. Smarty auto-escaping enabled on all view templates. |
| **Cross-Site Request Forgery (CSRF)** | A01:2021-Broken Access | Per-session cryptographic CSRF tokens verified via `Csrf::validate()` on all state-altering POST requests. |
| **Insecure Direct Object Reference (IDOR)** | A01:2021-Broken Access | Document retrieval and verification operations validate that the requesting user strictly owns the record or holds WHMCS admin privileges. |
| **Path Traversal / LFI** | A01:2021-Broken Access | Document files are saved outside the public web root (`public_html`). Stored filenames are randomly generated UUIDs (`bin2hex(random_bytes(16))`). Original client filenames are never used on disk. |
| **File Upload RCE / Polyglots** | A04:2021-Insecure Design | Deep validation pipeline (`FileValidator`): checks file extension, declared MIME type, binary magic-byte signatures, image header validity (`getimagesize`), and disallows multi-extension files (`.php.jpg`). |
| **Server-Side Request Forgery (SSRF)** | A10:2021-SSRF | HTTP client (`app/Helpers/Http.php`) strictly restricts protocols to `CURLPROTO_HTTPS | CURLPROTO_HTTP`, disables unverified redirections, and validates destinations. |
| **Webhook Forgery** | A02:2021-Cryptographic Failures | Inbound webhooks require an HMAC-SHA256 signature calculated over timestamp and raw request body, verified using constant-time `hash_equals()`. |
| **Replay Attacks** | A07:2021-Identification & Auth | 300-second maximum timestamp drift allowed. Unique event IDs stored in `mod_cv_webhook_events` to enforce idempotency. |
| **API Token Compromise** | A07:2021-Identification & Auth | Raw tokens are never stored. Only SHA-256 hashes are persisted. Tokens support granular scopes, expirations, and instant revocation. |
| **Rate Limit / Brute Force** | A04:2021-Insecure Design | Fixed-window database-backed rate limiter (`RateLimiter`) enforcing limits per IP/Client/Token for verification starts, uploads, and API calls. |
| **CSV Formula Injection** | A03:2021-Injection | Data exports automatically prefix cells starting with `=`, `+`, `-`, or `@` with a single quote (`'`) to disable spreadsheet formula execution. |
| **Data Exposure at Rest** | A02:2021-Cryptographic Failures | Optional at-rest AES-256-CBC encryption for all documents on disk. Personal information isolated in dedicated tables. |

---

## 2. Cryptographic Standards & Webhook Verification

### Inbound Webhook Signature Scheme
When Didit dispatches a webhook event to `api/webhook.php`, the header `Didit-Signature` is formatted as:
```
Didit-Signature: t=1756000000,v1=5d41402abc4b2a76b9719d911017c592
```

Validation steps performed:
```php
// 1. Timestamp freshness check
if (abs(time() - (int)$timestamp) > 300) {
    throw new \Exception("Webhook timestamp outside tolerance window.");
}

// 2. Compute expected HMAC
$signedData = $timestamp . '.' . $rawHttpBody;
$expected = hash_hmac('sha256', $signedData, $webhookSecret);

// 3. Constant-time comparison
if (!hash_equals($expected, $providedSignature)) {
    throw new \Exception("Invalid HMAC signature.");
}
```

---

## 3. License System Security

- **Real-Time Live Checks**: The module verifies domain authorization and license status directly and securely.
- **Fast Client Fallback**: Client area and checkout flows use low-overhead cached evaluation to eliminate latency while maintaining strict security.
- **Auto-Lock on Invalidation**: If a license becomes Suspended, Terminated, or Expired, administrative verification actions are safely restricted.

---

## 4. Credential Storage & Privacy Guarantees

- **Encrypted Sensitive Settings**: Third-party API keys, webhook secrets, and storage encryption keys are stored encrypted using WHMCS's internal encryption engine or AES-256-CBC fallback.
- **No Plaintext Logging**: Secrets, passwords, raw API tokens, and sensitive PII are stripped before writing to audit logs or error handlers.
- **Zero Remote Telemetry**: The module only connects to the user-configured KYC provider (Didit) and the authorized licensing server.
