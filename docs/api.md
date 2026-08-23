# REST API v1 Documentation

The **Advanced Client Verification** module includes a full-featured REST API for integrating identity verification into custom mobile apps, single-page portals, or external backend services.

---

## 1. Authentication & Security

All API endpoints require authentication using a **Bearer Token** sent in the HTTP `Authorization` header:

```http
Authorization: Bearer cv_tok_abcdef1234567890...
```

### Key Security Features
- **SHA-256 Hashed Storage**: Raw tokens are never stored in the database. Only their SHA-256 hashes are persisted.
- **Granular Scopes**:
  - `read`: Access verification records, documents, and client status.
  - `write`: Create verifications, update details, trigger manual reviews.
  - `admin` / `*`: Full control including approving and rejecting verifications.
- **Sliding-Window Rate Limiting**: Enforced per-token per-minute to protect against denial-of-service or brute force.
- **Expirable & Revocable**: Instant revocation and automatic expiration handling.

---

## 2. API Endpoints

**Base URL**: `https://yourdomain.com/modules/addons/clientverification/api/v1`

---

### 1. Get Verification by ID
Retrieve details and current status of a specific verification record.

- **Method**: `GET`
- **Path**: `/verification/{id}`
- **Required Scope**: `read`
- **Example Request**:
  ```bash
  curl -X GET "https://yourdomain.com/modules/addons/clientverification/api/v1/verification/1042" \
       -H "Authorization: Bearer YOUR_API_TOKEN" \
       -H "Accept: application/json"
  ```
- **Example Response (200 OK)**:
  ```json
  {
    "success": true,
    "data": {
      "id": 1042,
      "client_id": 501,
      "status": "approved",
      "mode": "hybrid",
      "provider": "didit",
      "risk_score": 12,
      "submitted_at": "2026-08-20 14:32:00",
      "verified_at": "2026-08-20 14:35:12",
      "expires_at": "2027-08-20 14:35:12"
    }
  }
  ```

---

### 2. Get Client Active Verification
Retrieve the current active verification status for a specific WHMCS client.

- **Method**: `GET`
- **Path**: `/verification/client/{clientId}`
- **Required Scope**: `read`
- **Example Response (200 OK)**:
  ```json
  {
    "success": true,
    "data": {
      "client_id": 501,
      "is_verified": true,
      "verification": {
        "id": 1042,
        "status": "approved",
        "verified_at": "2026-08-20 14:35:12"
      }
    }
  }
  ```

---

### 3. Create Verification Session
Initiate a new verification session for a client.

- **Method**: `POST`
- **Path**: `/verification`
- **Required Scope**: `write`
- **Headers**: `Content-Type: application/json`
- **Request Body**:
  ```json
  {
    "client_id": 501,
    "mode": "hybrid",
    "personal_data": {
      "first_name": "Jane",
      "last_name": "Doe",
      "country": "US"
    }
  }
  ```
- **Example Response (201 Created)**:
  ```json
  {
    "success": true,
    "data": {
      "verification_id": 1045,
      "client_id": 501,
      "mode": "hybrid",
      "status": "pending",
      "redirect_url": "https://verify.didit.me/session/sess_9a8b7c6d5e",
      "method": "didit"
    }
  }
  ```

---

### 4. Approve Verification
Manually approve a pending or under-review verification record.

- **Method**: `POST`
- **Path**: `/verification/{id}/approve`
- **Required Scope**: `write` or `*`
- **Request Body** *(optional notes)*:
  ```json
  {
    "notes": "Approved via external CRM review."
  }
  ```
- **Example Response (200 OK)**:
  ```json
  {
    "success": true,
    "message": "Verification #1042 has been approved successfully."
  }
  ```

---

### 5. Reject Verification
Reject a verification record and optionally provide a reason.

- **Method**: `POST`
- **Path**: `/verification/{id}/reject`
- **Required Scope**: `write` or `*`
- **Request Body**:
  ```json
  {
    "reason": "Expired identity document provided."
  }
  ```
- **Example Response (200 OK)**:
  ```json
  {
    "success": true,
    "message": "Verification #1042 has been rejected."
  }
  ```

---

## 3. Standard HTTP Response Codes

| Status Code | Meaning | Description |
|-------------|---------|-------------|
| `200 OK` | Success | The request succeeded. |
| `201 Created` | Created | The verification session was created. |
| `400 Bad Request` | Invalid Input | Malformed JSON or missing required fields. |
| `401 Unauthorized` | Auth Failed | Missing, invalid, or expired Bearer token. |
| `403 Forbidden` | Scope Violation | Token lacks required scope permissions. |
| `404 Not Found` | Not Found | Requested verification or client ID was not found. |
| `429 Too Many Requests` | Rate Limited | Token exceeded its configured requests-per-minute limit. |
| `500 Internal Error` | Server Error | An unexpected server error occurred. |

---

## 4. Managing API Tokens in Admin Panel

Administrators can generate and manage tokens from **WHMCS Admin** → **Advanced Client Verification** → **API Tokens**:
1. Click **Create API Token**.
2. Enter a friendly **Name / Description** (e.g. `Mobile App Backend`).
3. Select permissions (`read`, `write`, `*`).
4. Set an optional **Expiration Date** and **Rate Limit** (requests/min).
5. Copy the generated raw token immediately (it will not be shown again).
