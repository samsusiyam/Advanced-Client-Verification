# Advanced Client Verification for WHMCS

[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-blue.svg)](https://php.net)
[![WHMCS Compatibility](https://img.shields.io/badge/WHMCS-8.x%20%7C%209.x-green.svg)](https://whmcs.com)
[![License](https://img.shields.io/badge/License-HostNibo-blueviolet.svg)](https://hostnibo.com)

A production-ready, enterprise-grade **KYC (Know Your Customer) and Identity Verification** module for **WHMCS**.

Built by [HostNibo](https://hostnibo.com), this module delivers flexible, compliant, and fraud-resistant identity verification for web hosting, cloud infrastructure, domain registrars, and digital service providers.

---

## 🌟 Key Features

### 1. Flexible Verification Modes
- **Hybrid Mode (Recommended)**: Seamless user onboarding where users verify automatically via Didit; approved users can instantly activate services, while ambiguous or high-risk cases automatically drop into the manual admin review queue.
- **Manual Mode**: Direct document upload (Passport, National ID, Driver's License, Selfie, Proof of Address) with admin review and custom rejection/information requests.
- **Didit Automated Mode**: Fully biometric, AI-assisted identity verification with instant liveness detection and document authentication.

### 2. Extensible Provider Architecture
- Clean adapter pattern powered by `KycProviderInterface`.
- Comes built-in with `ManualProvider` and `DiditProvider`.
- Easily extendable to integrate third-party KYC providers (e.g. Sumsub, Veriff, Persona) without modifying core verification logic.

### 3. Server-Side Checkout Guard
- Enforce KYC requirements globally, per product/service, or per client group.
- Intercepts checkout at the server level via WHMCS hooks (`ShoppingCartValidateCheckout`) to prevent unverified customers from completing high-risk purchases.
- Clear, user-friendly warnings directing clients to complete verification.

### 4. Enterprise HostNibo ELMS License Manager
- Integrated real-time license verification with [HostNibo ELMS](https://hostnibo.com).
- Instant live status validation in the Admin Area with automatic domain matching and real-time status detection (Active, Suspended, Expired, Terminated).
- High-speed cached evaluation for client area pages and checkout guard to ensure zero latency during client checkout.

### 5. Bank-Grade Security & Document Storage
- **Isolated Storage**: Documents are stored **outside the web root** (`public_html`) with cryptographically random filenames.
- **At-Rest Encryption**: Optional AES-256-CBC encryption for all stored files.
- **Strict File Validation**: Deep validation inspecting MIME types, file signatures (magic bytes), file extensions, image integrity, and size limits (rejects double extensions and polyglot files).
- **Zero Shell Dependencies**: 100% pure PHP implementation — no `exec()`, `shell_exec()`, Node.js, Python, or Redis required. Shared hosting and cPanel compatible.

### 6. Risk Scoring & Duplicate Detection
- Configurable risk engine evaluating country mismatches, disposable email domains, proxy/VPN flags, and previous rejections.
- Duplicate document detection to prevent banned or fraudulent users from reusing documents across multiple accounts.

### 7. Inbound & Outbound Webhooks
- **Inbound Didit Webhooks**: Real-time webhook updates with HMAC-SHA256 signature verification, replay protection (5-minute timestamp tolerance), and idempotency tracking.
- **Outbound Webhooks**: Dispatch custom events (`verification.created`, `verification.approved`, `verification.rejected`, etc.) to your custom microservices or CRM signed with HMAC-SHA256.

### 8. Scoped REST API v1
- Complete REST API for headless workflows, mobile apps, or external management.
- Hashed token storage (SHA-256), customizable permission scopes (`read`, `write`, `*`), expiry dates, and per-minute rate limiting.

### 9. WHMCS Native Notifications & Auditing
- 6 native WHMCS email templates for verification status lifecycle events.
- Complete audit trail logging every admin action, webhook receipt, document upload, and risk calculation.
- Safe CSV exports with formula injection mitigation (escaping cells starting with `=`, `+`, `-`, `@`).

---

## 📋 Requirements

| Requirement | Supported Versions |
|-------------|--------------------|
| **PHP** | 8.1, 8.2, 8.3+ |
| **WHMCS** | 8.0 through 9.x |
| **Database** | MySQL 5.7+ / MariaDB 10.3+ |
| **PHP Extensions** | `curl`, `openssl`, `fileinfo`, `json`, `pdo_mysql` |
| **Protocol** | HTTPS (Required for webhooks and biometric camera access) |
| **Hosting** | Any cPanel, DirectAdmin, Plesk, Cloud, or VPS environment |

---

## 🚀 Installation & Setup

### Step 1: Upload Files
Upload the `clientverification` directory to your WHMCS addons path:
```
/path/to/whmcs/modules/addons/clientverification/
```

### Step 2: Activate the Module
1. Log in to your **WHMCS Admin Area**.
2. Navigate to **System Settings** → **Addon Modules** (or **Setup** → **Addon Modules**).
3. Locate **Advanced Client Verification** and click **Activate**.
4. Click **Configure**, select the administrative roles permitted to access the module, and save.

> **Note:** Activation automatically runs database migrations (`mod_cv_*`), sets default configurations, creates document types, and registers native email templates.

### Step 3: Activate Your License
1. Go to **Addons** → **Advanced Client Verification** → **License**.
2. Enter your **HostNibo License Key** obtained from your [HostNibo Client Portal](https://hostnibo.com).
3. Click **Activate License**.

### Step 4: Configure Storage & Settings
1. Create a secure directory **outside** your public web directory:
   ```bash
   mkdir -p /home/username/kyc_storage
   chmod 750 /home/username/kyc_storage
   ```
2. Navigate to **Module Settings** in the KYC admin panel.
3. Enter the absolute storage path (e.g. `/home/username/kyc_storage`).
4. Select your **Verification Mode** (Hybrid, Manual, or Didit).
5. If using Didit, enter your **API Key**, **Workflow ID**, and **Webhook Secret**.

### Step 5: Configure Webhooks (Optional for Automated KYC)
In your Didit developer console, configure your webhook endpoint to:
```
https://yourdomain.com/modules/addons/clientverification/api/webhook.php
```

### Step 6: Configure Cron Job
Add a cron job in cPanel or your server crontab to run maintenance tasks:
```bash
*/15 * * * * php /home/username/public_html/modules/addons/clientverification/cron.php >/dev/null 2>&1
```
*(Alternatively, daily maintenance runs automatically via the registered WHMCS `DailyCronJob` hook).*

---

## 🏛️ Architecture Overview

```
                          ┌───────────────────────────┐
                          │   Client / Checkout Flow  │
                          └─────────────┬─────────────┘
                                        │
                                        ▼
                          ┌───────────────────────────┐
                          │  Checkout Guard (Hook)    │
                          │   Product / Group Rules   │
                          └─────────────┬─────────────┘
                                        │
                                        ▼
                          ┌───────────────────────────┐
                          │    Verification Engine    │
                          │  Hybrid / Manual / Didit  │
                          └──────┬─────────────┬──────┘
                                 │             │
                    ┌────────────┘             └────────────┐
                    ▼                                       ▼
       ┌────────────────────────┐              ┌────────────────────────┐
       │     Didit Provider     │              │    Manual Provider     │
       │ (Automated Biometrics) │              │  (ID & Doc Submission) │
       └────────────┬───────────┘              └────────────┬───────────┘
                    │                                       │
                    ▼                                       ▼
       ┌────────────────────────┐              ┌────────────────────────┐
       │ Inbound Webhook Handler│              │   Admin Review Queue   │
       │ HMAC + Replay Check    │              │ Approve/Reject/Request │
       └────────────┬───────────┘              └────────────┬───────────┘
                    │                                       │
                    └───────────────────┬───────────────────┘
                                        ▼
                          ┌───────────────────────────┐
                          │  Risk & Decision Engine   │
                          │  Duplicate & Fraud Checks │
                          └─────────────┬─────────────┘
                                        │
                                        ▼
                          ┌───────────────────────────┐
                          │ Final Status & Email Alert│
                          │ (Approved/Rejected/Review)│
                          └───────────────────────────┘
```

---

## 🛡️ Security & Hardening

| Threat Model | Defense Mechanism |
|--------------|-------------------|
| **SQL Injection (SQLi)** | 100% parameterized queries via Laravel Capsule ORM. |
| **Cross-Site Scripting (XSS)** | Contextual sanitization (`Sanitizer::escape()`) and auto-escaped Smarty templates. |
| **Cross-Site Request Forgery (CSRF)**| Cryptographic per-session CSRF token validation on all state-changing requests. |
| **Insecure Direct Object Reference (IDOR)** | Strict ownership validation tying sessions, uploads, and data to authenticated client IDs. |
| **Path Traversal & Local File Inclusion** | Random UUID-based filenames stored strictly in configured path outside web root. |
| **Malicious File Uploads / RCE** | Multi-layer validation checking mime types, file signatures (magic bytes), and image headers. |
| **Server-Side Request Forgery (SSRF)** | Protocol allowlists (`https`/`http`) and cURL security constraints (`CURLOPT_PROTOCOLS`). |
| **Webhook Spoofing & Replay Attacks** | HMAC-SHA256 signature verification with constant-time comparison and 300-second timestamp windows. |
| **API Token Theft & Brute Force** | SHA-256 hashed token storage, scoped access control, and sliding-window rate limiters. |
| **CSV Formula Injection** | Automatic cell escaping for cells starting with `=`, `+`, `-`, or `@`. |

For complete security specifications, see [docs/security.md](docs/security.md).

---

## 📚 Documentation Index

- 📖 [Installation Guide](docs/installation.md) - Detailed setup steps and troubleshooting.
- ⚙️ [Configuration Guide](docs/configuration.md) - Comprehensive explanation of all settings and rules.
- 🤖 [Didit Integration](docs/didit.md) - Setup automated biometric verification workflows.
- 🔌 [REST API v1 Documentation](docs/api.md) - Endpoints, request schemas, authentication, and examples.
- 🔒 [Security & Compliance](docs/security.md) - Threat mitigations, encryption, and data protection.
- 🛠️ [Developer Guide](docs/development.md) - Architecture details, creating custom providers, and running tests.

---

## 🧪 Testing

The repository contains automated unit, integration, and security test suites using PHPUnit:

```bash
# Run the test suite
composer test
```

---

## 📞 Support & Inquiries

- **Author**: [HostNibo](https://hostnibo.com)
- **Official Website**: [https://hostnibo.com](https://hostnibo.com)
- **Developer Contact & Bio**: [https://siyam.bio.link](https://siyam.bio.link)

---

## 📄 License

This software is developed and licensed by **HostNibo**. See the `LICENSE` file for details.
