# Advanced Client Verification

A production-ready, open-source **KYC (Know Your Customer)** module for WHMCS.
Supports **Manual**, **Didit automated**, and **Hybrid** verification flows with
secure document storage, a risk engine, checkout guard, audit logging, email
notifications, cron, webhooks, and a REST API.

> MIT Licensed. No hidden license checks, backdoors, remote kill switches, or
> unauthorized telemetry. Didit is an optional external provider — the core
> module works without it.

## Requirements

- PHP 8.1+
- WHMCS 8.x / 9.x
- MySQL / MariaDB
- cURL, OpenSSL, Fileinfo extensions
- HTTPS
- Shared hosting / cPanel compatible (no Docker, Node.js, Python, Redis,
  Supervisor, systemd, VPS, `shell_exec()`, or `exec()` required)

## Features

- **3 verification modes**: Manual, Didit Automated, Hybrid (default)
- **Provider adapter architecture**: `KycProviderInterface` with `ManualProvider`
  and `DiditProvider`; add Sumsub/Veriff/Persona/Custom without touching the core
- **Secure document upload**: extension + MIME + file-signature + size + image
  validation; double-extension and polyglot bypass rejected
- **Private document storage**: files stored **outside** `public_html` with random
  filenames and optional at-rest AES-256 encryption
- **Admin verification queue**: approve / reject / request info / suspend / review
- **Checkout Guard**: server-side checkout blocking for KYC-required products
- **Product & client-group KYC rules**
- **Risk engine + duplicate detection**: local rules can override an approved
  provider decision
- **Audit logs**, **rate limiting**, **CSV export**
- **WHMCS native email** notifications (no custom SMTP)
- **Cron** for expiration, reminders, retention, cleanup
- **Inbound webhooks** with signature, timestamp, vendor-data validation,
  replay protection, and idempotency
- **Outbound webhooks** signed with HMAC
- **REST API v1** with hashed, scoped, revocable, expirable, rate-limited tokens

## Installation

1. Upload the `clientverification/` folder to `modules/addons/` in your WHMCS root.
2. Go to **WHMCS → System Settings → Addon Modules**.
3. Activate **Advanced Client Verification**. Activation runs database migrations,
   creates default settings, document types, and email templates.
4. Configure the module (API key, workflow ID, webhook secret, storage path).
5. Set up a cron job (see `docs/cron` / `cron.php`).

See `docs/installation.md`, `docs/configuration.md`, and `docs/didit.md` for details.

## Architecture

```
WHMCS → KYC Module Core → Provider (Manual | Didit | Hybrid)
                                  ↓
                          Final Verification → Checkout Guard → Service/Product
```

Provider contract (add future providers easily):

```php
interface KycProviderInterface
{
    public function createSession(VerificationEntity $verification): KycSession;
    public function getStatus(string $sessionId): KycResult;
    public function handleWebhook(array $payload, array $headers): KycResult;
}
```

See `docs/architecture.md` (development.md) and `docs/security.md`.

## Security

The module defends against SQL Injection (parameterized queries), XSS (output
escaping), CSRF (token checks), IDOR (ownership/assignment checks), Path
Traversal (random filenames outside web root), File Upload RCE (signature/MIME
checks), SSRF (scheme allowlist + cURL protocol restrictions), Webhook Forgery
(signature + timestamp), Replay Attacks (idempotency table), API Token Theft
(hashed storage), Brute Force (rate limiting), CSV Injection (cell escaping),
and Mass Assignment (allowlist). See `docs/security.md`.

## Tests

PHPUnit unit, integration, and security tests are in `tests/`. Run with
`composer test` (requires PHPUnit). See `docs/development.md`.

## License

MIT — see `LICENSE`.
