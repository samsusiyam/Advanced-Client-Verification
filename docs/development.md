# Developer & Contributor Guide

This document outlines the architectural structure, extension patterns, development standards, and testing procedures for the **Advanced Client Verification** WHMCS module.

---

## 1. Project Directory Structure

```
modules/addons/clientverification/
├── clientverification.php   # Module entrypoint (config, activate, deactivate, upgrade, output, clientarea)
├── hooks.php               # WHMCS hook handlers (CheckoutGuard, DailyCronJob, Navbar)
├── cron.php                # Standalone CLI/cPanel cron maintenance worker
├── composer.json           # PSR-4 autoloading and testing definitions
├── phpunit.xml.dist        # PHPUnit configuration
├── whmcs.json              # WHMCS marketplace metadata definition
├── logo.png                # Addon module icon
│
├── admin/                  # Admin area UI routers & action controllers
│   ├── dashboard.php       # Main dashboard metrics, stats & status distribution
│   ├── verifications.php   # Verification list, filters, and search
│   ├── verification.php    # Single verification review & decision workflow
│   ├── documents.php       # Document type manager & requirements
│   ├── product-rules.php   # Product-level KYC enforcement rules
│   ├── group-rules.php     # Client group-level KYC rules
│   ├── settings.php        # General, Provider, Risk & Storage settings
│   ├── license.php         # HostNibo ELMS license manager & live status
│   ├── audit-logs.php      # Audit log viewer & filtering
│   ├── webhooks.php        # Inbound webhook logs & outbound webhook subscriptions
│   ├── api.php             # REST API token management
│   └── exports.php         # Safe CSV export generator
│
├── app/                    # PSR-4 Core Application Logic (`ClientVerification\`)
│   ├── Api/                # TokenAuth and REST API handlers
│   ├── Controllers/        # Shared controller logic
│   ├── Helpers/            # Helper utilities, HTTP client, audit logger, autoloading
│   ├── License/            # HostNibo ELMS License Manager
│   ├── Mail/               # WHMCS native email notification dispatcher
│   ├── Models/             # Entity models & database helpers
│   ├── Providers/          # KycProviderInterface, ManualProvider, DiditProvider
│   ├── Repositories/       # Data access repositories
│   ├── Risk/               # Dynamic risk scoring & duplicate document detection
│   ├── Security/           # Sanitizer, Csrf token engine, RateLimiter
│   ├── Services/           # VerificationService, HybridVerificationService, ProviderFactory
│   ├── Storage/            # DocumentStorage & AES-256 encryption engine
│   ├── Validation/         # FileValidator & binary signature analyzer
│   └── Webhooks/           # DiditWebhookHandler & OutboundWebhook dispatcher
│
├── client/                 # Client Area controllers
│   ├── verification.php    # Verification start & status router
│   ├── upload.php          # Secure document upload handler
│   └── status.php          # Real-time polling endpoint
│
├── database/               # Database migrations & schemas
│   └── migrations/         # Versioned SQL migration files
│
├── docs/                   # Documentation markdown files
│   ├── api.md
│   ├── configuration.md
│   ├── development.md
│   ├── didit.md
│   ├── installation.md
│   └── security.md
│
├── lang/                   # Internationalization / Language dictionaries
│   └── english.php
│
├── templates/              # Smarty templates for Admin & Client Area
│   ├── admin/
│   └── client/
│
└── tests/                  # Automated test suites
    ├── Unit/               # Unit tests (Risk, Validation, Sanitizer, etc.)
    ├── Integration/        # Workflow & database integration tests
    └── Security/           # Security tests (CSRF, IDOR, HMAC, Polyglot, CSV)
```

---

## 2. Architecture & Provider Pattern

All identity verification providers implement the standard `KycProviderInterface`:

```php
namespace ClientVerification\Providers;

use ClientVerification\Models\VerificationEntity;
use ClientVerification\Models\KycSession;
use ClientVerification\Models\KycResult;

interface KycProviderInterface
{
    /**
     * Initiate a new verification session with the provider.
     */
    public function createSession(VerificationEntity $verification): KycSession;

    /**
     * Poll or fetch the current status of an external session.
     */
    public function getStatus(string $sessionId): KycResult;

    /**
     * Parse and validate an inbound webhook callback.
     */
    public function handleWebhook(array $payload, array $headers): KycResult;
}
```

### Adding a New Provider
To add a new KYC provider (e.g. *Sumsub*, *Veriff*, or *Persona*):
1. Create `app/Providers/CustomProvider.php` implementing `KycProviderInterface`.
2. Register the provider in `app/Services/ProviderFactory.php`:
   ```php
   switch ($providerName) {
       case 'custom':
           return new CustomProvider($config);
       // ...
   }
   ```
3. Add any necessary provider-specific settings in `admin/settings.php`.

The core verification workflow, checkout guard, risk engine, and email notifications will work automatically with the new provider without changes.

---

## 3. Database Migrations

Migrations are stored in `database/migrations/` and run automatically during module activation or upgrade:
- `01_create_verifications_table.sql`
- `02_create_documents_table.sql`
- `03_create_document_types_table.sql`
- `04_create_settings_table.sql`
- `05_create_audit_logs_table.sql`
- `06_create_webhook_events_table.sql`
- `07_create_api_tokens_table.sql`
- `08_create_rules_tables.sql`
- `09_create_rate_limits_table.sql`

All database operations must use WHMCS's Illuminate Database Capsule (`\Illuminate\Database\Capsule\Manager`).

---

## 4. Running Tests

The test suite uses PHPUnit:

```bash
# Install development dependencies
composer install

# Run all test suites
composer test

# Run a specific test suite
vendor/bin/phpunit tests/Unit
vendor/bin/phpunit tests/Security
```

### Testing Categories
- **Unit Tests**: Test isolated components (RiskEngine scoring, FileValidator magic bytes, Sanitizer escaping, RateLimiter windows).
- **Integration Tests**: Test full verification lifecycles, database persistence, and provider adapters.
- **Security Tests**: Validate HMAC webhook signature verification, replay attack rejection, IDOR guards, CSV formula escaping, and polyglot upload rejection.

---

## 5. Coding Standards & Guidelines

- **PHP 8.1+ Type Safety**: Use strict type hints for all class properties, method parameters, and return types.
- **No Dangerous Execution**: Do **not** use `exec()`, `shell_exec()`, `passthru()`, `system()`, or `eval()`.
- **Database Safety**: Never concatenate variables into SQL queries. Always use parameterized queries or query builder bindings.
- **Output Escaping**: Escape all dynamic variables rendered in views via `Sanitizer::escape()`.
- **Error Handling**: Gracefully catch exceptions and log actionable details to the internal audit log rather than exposing raw stack traces to end users.
