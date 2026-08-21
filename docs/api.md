# REST API v1

Base URL: `https://yourdomain.com/modules/addons/clientverification/api/v1/`

All requests require an `Authorization: Bearer <token>` header. Tokens are
hashed, scoped (`read` / `write` / `*`), revocable, expirable, and rate limited.

## Endpoints

### GET /verification/{id}
Get a verification by id. Scope: `read`.

### GET /verification/client/{clientId}
Get the active verification for a client. Scope: `read`.

### POST /verification
Start a verification.
```json
{
  "client_id": 678,
  "mode": "hybrid",
  "personal_data": { "first_name": "A", "last_name": "B" }
}
```
Scope: `write`. Returns `{ verification_id, redirect_url, method }`.

### POST /verification/{id}/approve
Approve a verification via API. Scope: `write`.

### POST /verification/{id}/reject
Reject a verification via API. Scope: `write`.

## Responses
- `200` success
- `201` created
- `401` unauthorized (bad/missing token)
- `403` forbidden (scope/disabled)
- `429` rate limited
- `404` not found

## Creating a token

Tokens are created and managed from the module's **API Tokens** admin page
(`addonmodules.php?module=clientverification&action=api`). Select the scopes
(`read`, `write`, or `*` for full access), an optional expiry, and a per-minute
rate limit. The raw bearer token is shown only once at creation time — copy it
immediately. Existing tokens can be revoked, re-activated, or deleted from the
same page.

For reference (equivalent raw DB insert into `mod_cv_api_tokens`):
- `name`: label
- `token_hash`: `hash('sha256', $rawToken)`
- `scopes`: JSON array e.g. `["read","write"]`
- `active`: 1
- `expires_at`: optional datetime
- `rate_limit`: requests per minute

The raw token should only be displayed once at creation time.
