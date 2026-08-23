# Installation & Deployment Guide

This guide walks you through installing and configuring the **Advanced Client Verification** module in your WHMCS environment.

---

## 1. System Requirements

Ensure your server meets the following requirements before proceeding:

- **PHP**: 8.1, 8.2, or 8.3+
- **WHMCS**: 8.0 through 9.x
- **Database**: MySQL 5.7+ or MariaDB 10.3+
- **PHP Extensions**:
  - `curl` (for Didit API and HostNibo ELMS license verification)
  - `openssl` (for AES-256 document encryption and HMAC signatures)
  - `fileinfo` (for MIME-type and magic-byte inspection)
  - `json` & `pdo_mysql`
- **SSL / HTTPS**: Mandatory for client biometric cameras and secure webhook processing.
- **Environment Compatibility**: Fully compatible with shared hosting (cPanel, DirectAdmin, Plesk), Cloud VMs, and dedicated servers without requiring Node.js, Python, Redis, or root privileges.

---

## 2. Uploading Files

1. Download or clone the release files.
2. Ensure the directory is named `clientverification`.
3. Upload the entire `clientverification` folder to your WHMCS addons path:

```
<WHMCS_ROOT>/modules/addons/clientverification/
```

### Directory Structure Check
After uploading, your structure should look like:
```
<WHMCS_ROOT>/modules/addons/clientverification/
├── admin/
├── api/
├── app/
├── client/
├── database/
├── docs/
├── lang/
├── templates/
├── clientverification.php
├── cron.php
└── hooks.php
```

---

## 3. Module Activation

1. Log in to your **WHMCS Admin Area**.
2. Navigate to **System Settings** → **Addon Modules** (or **Setup** → **Addon Modules** in WHMCS 8.x).
3. Scroll down to locate **Advanced Client Verification**.
4. Click **Activate**.
5. Once activated, click **Configure** on the right side:
   - Check the **Access Control** checkboxes for the Administrator roles who should have access to manage verifications.
   - Click **Save Changes**.

> 💡 **What happens on activation:**
> - Runs database migrations automatically to create all `mod_cv_*` tables.
> - Initializes default system settings and risk parameters.
> - Pre-populates standard document types (*Passport, National ID, Driver's License, Selfie, Proof of Address*).
> - Creates 6 WHMCS native email templates for KYC status notifications.

---

## 4. License Activation (HostNibo ELMS)

1. In the WHMCS Admin navigation bar, go to **Addons** → **Advanced Client Verification**.
2. Click on the **License** tab in the top navigation.
3. Enter your **HostNibo License Key** obtained from your [HostNibo Client Area](https://hostnibo.com).
4. Click **Activate License**.
5. The system will perform an instant live check against the licensing server and display your license status, registered domain, and expiry date.

---

## 5. Storage Directory Setup (Secure Document Isolation)

For security compliance and data privacy, user-submitted identity documents must be stored **outside the public web root** (`public_html`).

### Creating the Directory
Run via SSH or File Manager in your cPanel home directory:

```bash
# Example for cPanel user 'username'
mkdir -p /home/username/kyc_storage
chmod 750 /home/username/kyc_storage
```

### Configuring the Path in WHMCS
1. Go to **Addons** → **Advanced Client Verification** → **Settings**.
2. Under the **Storage & Security** section, enter the absolute path:
   ```
   /home/username/kyc_storage
   ```
3. *(Optional)* Check **Enable Document Encryption** to enable at-rest AES-256-CBC encryption for all uploaded files.
4. Click **Save Settings**.

---

## 6. Configuring Verification Provider (Didit Automated KYC)

If using **Hybrid** or **Didit** verification mode:

1. Register for an account at [Didit](https://didit.me).
2. Create a verification workflow and obtain your:
   - **API Key**
   - **Workflow ID**
   - **Webhook Secret**
3. In WHMCS, go to **Advanced Client Verification** → **Settings**.
4. Under **Provider Settings**:
   - Set **Verification Mode** to `Hybrid (Recommended)` or `Didit`.
   - Enter your **Didit API Key**, **Workflow ID**, and **Webhook Secret**.
   - Set **On Provider Error** to `Manual Review` (ensures fraud resistance).
5. In your Didit Dashboard, set your Webhook URL to:
   ```
   https://yourdomain.com/modules/addons/clientverification/api/webhook.php
   ```

---

## 7. Cron Job Setup

The module includes an automated maintenance script (`cron.php`) that handles:
- Expiration warnings and reminders.
- Marking expired verifications.
- Enforcing document retention policies.
- Cleaning temporary upload artifacts and stale rate-limit logs.

### Setting up Cron in cPanel
1. Go to **cPanel** → **Cron Jobs**.
2. Add a new cron job scheduled every 15 minutes (or once daily):
   ```bash
   php -q /home/username/public_html/modules/addons/clientverification/cron.php >/dev/null 2>&1
   ```

*(Note: The module also hooks into WHMCS's native `DailyCronJob` hook as an automated fallback).*

---

## 8. Verification & Health Check

1. **Client Area Test**: Log in to a client account, visit `clientarea.php?m=clientverification`, and confirm that the verification prompt and document upload/Didit redirect render cleanly.
2. **Admin Area Test**: Visit the admin dashboard at `addonmodules.php?module=clientverification` and verify that the metrics, charts, and verification queues load without warnings.
3. **Webhook Test**: Trigger a test webhook from the Didit console to verify that `api/webhook.php` returns a `200 OK` and logs the event in the audit log.
