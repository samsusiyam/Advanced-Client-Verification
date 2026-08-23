# Changelog

## 1.0.0
- Initial production release by HostNibo.
- Enterprise HostNibo ELMS License Manager with real-time domain authorization and fast client checkout caching.
- Manual, Didit Automated, and Hybrid verification modes with smart fallback.
- Provider adapter architecture (`KycProviderInterface`, `ManualProvider`, `DiditProvider`).
- Secure document upload and private storage (outside `public_html`) with optional AES-256-CBC encryption.
- Admin verification queue with approve, reject, request information, suspend, and manual review actions.
- Checkout Guard (server-side) with product-level and client-group KYC enforcement rules.
- Dynamic risk engine and duplicate document detection.
- Comprehensive audit logging and sliding-window rate limiting.
- WHMCS native email notification templates for all verification lifecycle events.
- Automated cron tasks for expiration reminders, status transitions, retention enforcement, and temp cleanup.
- Inbound Didit webhook with HMAC-SHA256 signature validation, timestamp replay protection, and idempotency tracking.
- Outbound webhooks signed with HMAC-SHA256.
- Scoped REST API v1 with hashed tokens (SHA-256), granular permissions, and per-token rate limits.
- Safe CSV export with formula injection mitigation.
- Versioned database migrations and multi-language support.
- Automated unit, integration, and security test suites.
