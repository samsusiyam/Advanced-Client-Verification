# Configuration

## Verification Modes
- **Hybrid (default):** Client starts with Didit; approved results can auto-approve
  when safe, otherwise fall back to manual review. Provider errors never auto-approve.
- **Manual:** Client submits documents, admin reviews and decides.
- **Didit:** Fully automated; results drive auto-approve or manual review.

## Didit Configuration
| Field | Notes |
|-------|-------|
| API Key | Encrypted at rest |
| Workflow ID | Didit workflow identifier |
| Webhook Secret | Used to verify inbound webhook HMAC signatures |
| Mode | Hybrid / Manual / Didit |
| Auto Approve | Enable when Didit result is safe (risk engine still applies) |
| On Provider Error | Manual Review (recommended) — never auto-approve |

## Storage
- **Storage Path:** absolute path outside `public_html`, e.g. `/home/USERNAME/kyc-storage`
- **Document Encryption:** optional AES-256-CBC at rest; files use random filenames

## Risk Thresholds
- **Auto-Approve threshold** (0-100): below this and provider approved → auto-approve
- **Manual Review threshold** (0-100): between thresholds → manual review; above → reject

## Product & Group Rules
- Per-product: Required / Optional / Not Required
- Per-client-group: Required / Optional / Not Required
- Checkout Guard enforces "Required" on the server during checkout.

## Outbound Webhooks
Admin can register URLs for events: `verification.created`, `verification.approved`,
`verification.rejected`, `verification.review_required`, `verification.expired`.
Each payload is signed with `X-CV-Signature: t=<ts>,v1=<hmac>`.

## API Tokens
Created and stored hashed. Support scopes (`read`, `write`, `*`), expiry, and
per-minute rate limits. Use `Authorization: Bearer <token>`.
