# Configuration Reference Guide

This document provides a detailed reference for all configuration options available in the **Advanced Client Verification** module.

---

## 1. License Configuration

| Setting | Type | Description |
|---------|------|-------------|
| **License Key** | String | Module License Key. Validates domain authorization and module status. |

---

## 2. Verification Modes

Configure how identity verification workflows are executed under **Settings** → **General**:

### Mode Options
- **Hybrid (Default & Recommended)**:
  - Users first attempt automated biometric verification via Didit.
  - If Didit verifies the user and risk scores are within safe bounds, the user is **auto-approved**.
  - If Didit encounters ambiguous data, errors, or if the risk engine detects anomalies, the request automatically falls back to the **Manual Review Queue** for admin inspection.
- **Manual**:
  - Direct document upload via WHMCS Client Area.
  - Clients upload required files (e.g. Passport, ID card, selfie, utility bill).
  - Admins review and manually approve, reject, or request additional information.
- **Didit Automated**:
  - Relies exclusively on Didit's biometric verification workflow.
  - Immediate redirect to Didit verification portal.

---

## 3. Provider Settings (Didit)

| Setting | Type | Description |
|---------|------|-------------|
| **API Key** | String | Secret API Key provided by Didit. Stored encrypted in the database. |
| **Workflow ID** | String | The unique workflow identifier configured in your Didit dashboard. |
| **Webhook Secret** | String | Secret key used to verify incoming Didit HMAC-SHA256 signatures. |
| **Auto-Approve on Success** | Boolean | Automatically approve client accounts when Didit returns an approved status (subject to risk engine thresholds). |
| **On Provider Error** | Dropdown | Action to take if Didit API is unreachable or returns an error. Options: `Manual Review` (Recommended) or `Reject`. Never set to auto-approve. |

---

## 4. Storage & Encryption Settings

| Setting | Type | Description |
|---------|------|-------------|
| **Storage Path** | Filepath | Absolute server directory path located **outside** `public_html` (e.g. `/home/username/kyc_storage`). |
| **Enable Encryption** | Boolean | Encrypt all stored document files at rest using AES-256-CBC with a randomly generated encryption key. |
| **Max File Size** | Integer | Maximum allowable upload size in megabytes (e.g., `10` MB). |
| **Allowed File Types** | Multi-select | Allowed document extensions (`jpg`, `jpeg`, `png`, `pdf`, `webp`). |
| **Data Retention Period** | Integer | Number of days to retain rejected or expired document files before automatic deletion by cron (e.g., `90` days; set `0` for indefinite). |

---

## 5. Risk Engine & Thresholds

The module calculates a dynamic composite risk score between `0` (clean) and `100` (critical risk) for every verification session.

| Parameter | Recommended Value | Description |
|-----------|-------------------|-------------|
| **Auto-Approve Threshold** | `30` | If Didit verifies the client and calculated Risk Score is $\le 30$, instant approval is granted. |
| **Manual Review Threshold** | `70` | Risk scores between `31` and `70` are routed to the Admin Review Queue. |
| **Auto-Reject Threshold** | `> 70` | Risk scores exceeding `70` trigger an immediate hold or rejection. |
| **Country Mismatch Penalty** | `+25` | Added to risk score if client IP country differs from billing address or identity document country. |
| **Disposable Email Penalty** | `+35` | Added if user registered with a known temporary/disposable email provider. |
| **Duplicate Document Check**| Enabled | Detects if the same ID number or document hash was previously used by another client account. |

---

## 6. Checkout Guard: Product & Client Group Rules

Control when and where verification is required:

### Product-Level Rules (**Admin** → **Product Rules**)
- Set rules per product / service package:
  - **Required**: Client *must* have an approved verification before completing checkout for this product. Unverified checkouts are blocked with a clear warning.
  - **Optional**: Client is prompted to verify, but checkout is permitted.
  - **Not Required**: Verification is ignored for this product.

### Client Group Rules (**Admin** → **Group Rules**)
- Apply KYC rules across entire WHMCS Client Groups (e.g., *Resellers*, *High-Risk*, *VIP*).

---

## 7. Outbound Webhooks

Dispatch real-time KYC events to external services (e.g. Slack bots, external billing systems, CRMs):

### Supported Events
- `verification.created`
- `verification.submitted`
- `verification.approved`
- `verification.rejected`
- `verification.review_required`
- `verification.expired`

### Signature Format
All outbound webhook HTTP POST requests contain an HMAC signature header:
```http
X-CV-Signature: t=1756000000,v1=6d2b3c4...
```
Verification algorithm:
```php
$expected = hash_hmac('sha256', $timestamp . '.' . $rawJsonBody, $webhookSecret);
if (hash_equals($expected, $v1)) {
    // Valid signature
}
```

---

## 8. REST API Tokens

Manage API tokens in **Admin Area** → **API Tokens**:
- **Scopes**:
  - `read`: Query verification statuses and client histories.
  - `write`: Submit verifications, trigger reviews, approve/reject.
  - `*`: Full administrative API access.
- **Rate Limits**: Configurable requests per minute (default: 60 rpm).
- **Expiration**: Optional token validity date.
- **Security**: Raw tokens are hashed with SHA-256 upon creation and displayed only once.
