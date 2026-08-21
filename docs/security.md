# Security

The module is built to defend against the OWASP-style threat model for KYC systems.

| Threat | Mitigation |
|--------|------------|
| SQL Injection | All DB access uses parameterized queries via Illuminate Capsule |
| XSS | Output escaped with `htmlspecialchars` (Sanitizer::escape); Smarty auto-escape in templates |
| CSRF | `Csrf` token generated per session and verified on every state-changing POST |
| IDOR | Ownership/assignment checks on documents and verifications; admin permission respected |
| Path Traversal | Documents stored outside web root with random filenames; original names never used on disk |
| File Upload RCE | Extension + MIME + file-signature + size + image-validity checks; double-extension and polyglot rejected |
| SSRF | cURL scheme allowlist (http/https), protocol restriction, no redirects abuse |
| Webhook Forgery | HMAC-SHA256 signature verification on inbound Didit webhooks |
| Replay Attack | Timestamp validation (5-min) + idempotency table `mod_cv_webhook_events` |
| API Token Theft | Tokens stored as SHA-256 hashes; raw token shown once; revocable + expirable |
| Brute Force | `RateLimiter` per client/IP on verification start and uploads |
| Rate Limit Bypass | Server-enforced fixed-window limiter in DB |
| CSV Injection | Each cell prefixed with `'` when starting with `= + - @` |
| Mass Assignment | `Sanitizer::only()` allowlist when persisting input |
| Sensitive Data Exposure | Personal data isolated in `mod_cv_personal_data`; credentials encrypted; documents stored privately |

## Webhook signature verification
```
expected = HMAC-SHA256( "<timestamp>.<rawBody>", webhook_secret )
compare with header v1 using hash_equals (constant-time)
reject if |now - timestamp| > 300s
```

## Credentials
API key, webhook secret, encryption key, and outbound webhook secret are stored
encrypted (WHMCS `encrypt()` or AES-256-CBC fallback). Plaintext secrets are
never written to logs.

## Open source guarantees
No license checks, no backdoors, no remote kill switch, no telemetry. Didit is
optional.
