# Didit Integration

Didit is integrated as a **provider adapter** (`DiditProvider`) behind the
`KycProviderInterface`. The core engine never references Didit directly, so you
can add Sumsub, Veriff, Persona, or a custom API later without modifying the
verification engine.

## Flow
1. Client clicks **Verify Identity** in the client area.
2. Module calls `DiditProvider::createSession()` with a `vendor_data` reference
   of the form `CV-<verification_id>-<client_id>` (e.g. `CV-12345-678`).
3. Didit returns a session id and a redirect URL; the client is redirected.
4. Client completes KYC on Didit.
5. Didit sends a webhook to `api/webhook.php`.
6. `DiditWebhookHandler` verifies:
   - **Signature** (`Didit-Signature: t=<ts>,v1=<hmac>` over `<ts>.<rawBody>`)
   - **Timestamp** (5-minute window, replay protection)
   - **Vendor-data** mapping → verification id + client id
   - **Client mapping** (session must belong to the claimed client — IDOR guard)
   - **Idempotency** (event already processed → `Already processed`)
7. `HybridVerificationService::applyResult()` runs the risk engine + decision
   engine and updates the verification.

## Client Mapping (critical for security)
The `vendor_data` round-trips to Didit and back. On webhook, it is parsed to
recover `verification_id` and `client_id`, ensuring no client can update another
client's verification.

## Provider error behavior
If Didit returns an error/timeout, the verification is moved to **manual review**
— it is **never** auto-approved.

## API calls
Implemented with a minimal cURL client (`app/Helpers/Http.php`): only http/https
schemes allowed, SSL peer/host verification enabled, protocol allowlist, no
`exec`/`shell_exec`. See `app/Providers/DiditProvider.php`.
