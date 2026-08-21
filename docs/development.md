# Development

## Project layout
```
modules/addons/clientverification/
├── clientverification.php   # module bootstrap (config/activate/output/clientarea)
├── hooks.php               # checkout guard + cron hooks
├── cron.php                # scheduled tasks
├── app/
│   ├── Providers/          # KycProviderInterface, ManualProvider, DiditProvider
│   ├── Services/           # VerificationService, HybridVerificationService, ProviderFactory
│   ├── Risk/               # RiskEngine
│   ├── Storage/            # DocumentStorage
│   ├── Security/           # Sanitizer, Csrf, RateLimiter
│   ├── Validation/         # FileValidator
│   ├── Webhooks/           # DiditWebhookHandler, OutboundWebhook
│   ├── Api/                # TokenAuth
│   ├── Mail/               # Notifier
│   ├── Helpers/            # functions.php (autoloader, config, audit)
│   └── Models/
├── admin/                  # admin pages
├── client/                 # client area pages
├── api/v1/                 # REST API router
├── database/migrations/    # numbered migrations
├── templates/              # admin/client templates
├── lang/                   # english.php
├── storage/                # .gitignore (runtime docs)
├── tests/                  # Unit / Integration / Security
└── docs/
```

## Autoloading
A PSR-style autoloader is registered in `app/Helpers/functions.php`, mapping the
`ClientVerification\` namespace to `app/`. For a Composer-based dev environment,
`composer.json` provides the same PSR-4 mapping.

## Adding a new provider
1. Create `app/Providers/MyProvider.php` implementing `KycProviderInterface`.
2. Add a case in `ProviderFactory::make()`.
No core-engine changes required.

## Running tests
```
composer install
composer test
```
PHPUnit is configured via `phpunit.xml.dist` (add at repo root). Tests cover
unit logic (RiskEngine, FileValidator, Sanitizer, RateLimiter), integration
(service flows with a test DB), and security (signature verification, auth,
CSV injection, upload bypass).

## Conventions
- No `shell_exec`/`exec`/Docker/Node/Python/Redis.
- All DB queries parameterized.
- Escape all output; validate all input.
- MIT licensed, no backdoors/telemetry.
