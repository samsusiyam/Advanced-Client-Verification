# Changelog

## 1.0.0
- Initial production release.
- Manual, Didit Automated, and Hybrid verification modes.
- Provider adapter architecture (`KycProviderInterface`, `ManualProvider`, `DiditProvider`).
- Secure document upload and private storage (outside public_html) with optional encryption.
- Admin verification queue with approve / reject / request information / suspend / manual review.
- Checkout Guard (server-side) with product and client-group KYC rules.
- Risk engine and duplicate document detection.
- Audit logs and rate limiting.
- WHMCS native email templates.
- Cron tasks (expiration, reminders, retention, cleanup).
- Inbound Didit webhook with signature/timestamp/vendor-data validation, replay protection, idempotency.
- Outbound webhooks (HMAC signed).
- REST API v1 with hashed, scoped, revocable, expirable, rate-limited tokens.
- CSV export with CSV-injection protection.
- Database migrations and localization support.
- Unit, integration, and security tests.
