# Installation

## 1. Upload
Upload the `clientverification/` directory to:

```
/public_html/modules/addons/clientverification/
```

## 2. Activate
- WHMCS Admin → **System Settings** → **Addon Modules**
- Find **Advanced Client Verification** and click **Activate**

Activation automatically:
- Runs all database migrations (`mod_cv_*`)
- Inserts default settings
- Inserts default document types (passport, driver's license, national ID, selfie, proof of address)
- Creates the WHMCS email templates (KYC Verification Started/Approved/Rejected/Manual Review Required/Additional Information Required/Expiring/Expired)

## 3. Configure & Activate License
Set the module configuration values (Addon Modules configuration screen or Module Admin Panel):
- **License Key**: Enter your HostNibo License Key (from [HostNibo Client Area](https://hostnibo.com))
- Verification Mode: Hybrid (default), Manual, or Didit
- Didit API Key, Workflow ID, Webhook Secret (stored encrypted)
- Storage path (outside `public_html`)
- Enable document encryption (optional)

## 4. Storage directory
Create a private directory **outside** your web root, e.g.:

```
/home/USERNAME/kyc-storage/
```

The web server user must have write access. Documents are never placed inside
`public_html/modules/...`.

## 5. Cron
Add a cron job (cPanel → Cron Jobs):

```
php /home/USERNAME/public_html/modules/addons/clientverification/cron.php
```

Or rely on the built-in WHMCS `DailyCronJob` hook (already registered).

## 6. Webhook URL (Didit)
Configure the Didit webhook/callback URL to:

```
https://yourdomain.com/modules/addons/clientverification/api/webhook.php
```
