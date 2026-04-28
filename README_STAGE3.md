# Insighta Labs+ Backend

A secure, role-based API for demographic profile intelligence with GitHub OAuth authentication, JWT token lifecycle management, and multi-interface integration.

---

## System Architecture

**Three-tier deployment:**
1. **Backend** (this repo) — PHP/Slim OAuth + API + RBAC
2. **CLI** (Go binary) — Global command-line tool with PKCE auth flow
3. **Web Portal** (Next.js) — HTTP-only cookies + CSRF protection

**Database:** SQLite with users, tokens, profiles, and audit tables.

**Auth Model:**
- GitHub OAuth with PKCE for both CLI and browser
- Access tokens (3-minute expiry) + refresh tokens (5-minute expiry)
- Single-use refresh token rotation with server-side invalidation
- Role-based access control: `admin` (full access) and `analyst` (read-only)

---

## Deployment

**Live URLs:**
- Backend: `https://profiles-api.duckdns.org`
- Web Portal: `https://insighta.example.com` (to be deployed)

**Tech Stack**

| Component | Technology |
|---|---|
| Framework | Slim PHP 4 |
| Database | SQLite (via PDO) |
| HTTP Client | Guzzle 7 |
| DI Container | PHP-DI 7 |
| Environment | vlucas/phpdotenv |
| IDs | UUID v7 (ramsey/uuid) |
| Crypto | PHP hash functions (SHA256) |
| Server | Nginx + PHP 8.4-FPM |

---

## Environment Variables

```bash
# Database
DB_PATH=database/profiles.db

# External APIs (Stage 2)
GENDERIZE_URL=https://api.genderize.io
AGIFY_URL=https://api.agify.io
NATIONALIZE_URL=https://api.nationalize.io

# Authentication (Stage 3)
ADMIN_EMAIL=akoshodi@gmail.com
GITHUB_CLIENT_ID=<your-github-oauth-app-id>
GITHUB_CLIENT_SECRET=<your-github-oauth-app-secret>
GITHUB_REDIRECT_URI=http://localhost:8000/auth/github/callback

# Web portal origin (for CORS and cookies)
WEB_ORIGIN=http://localhost:3000
COOKIE_SECURE=false  # true in production
```

---

## Authentication Flow

### CLI (Proof Key for Exchange — PKCE)

```
1. CLI generates:
   - state (CSRF protection)
   - code_verifier (48 bytes)
   - code_challenge = SHA256(code_verifier)

2. GET /auth/github?mode=cli&state=<state>&code_challenge=<challenge>
   Returns: authorize_url, state, code_verifier

3. User authorizes GitHub OAuth in browser

4. GitHub redirects to CLI's temporary localhost:8888/callback with `code`

5. CLI sends:
   POST /auth/github/exchange
   {
     "code": "...",
     "state": "...",
     "code_verifier": "..."
   }

6. Backend verifies PKCE, exchanges code with GitHub, returns:
   {
     "access_token": "...",
     "refresh_token": "...",
     "user": { "id", "username", "email", "role" }
   }

7. CLI stores credentials at ~/.insighta/credentials.json (mode 0600)
```

### Browser (Standard OAuth + HTTP-only Cookies)

```
1. User clicks "Continue with GitHub"

2. GET /auth/github?mode=web&redirect_uri=<portal-callback>
   Sets temporary oauth_state and pkce_verifier cookies (HttpOnly)

3. GitHub redirects to <portal-callback>?code=...&state=...

4. GET /auth/github/callback?code=...&state=...
   Backend verifies state cookie, exchanges code, returns:
   - Set-Cookie: access_token (HttpOnly, 3 min)
   - Set-Cookie: refresh_token (HttpOnly, 5 min)
   - Set-Cookie: csrf_token (accessible to JS, 5 min)

5. Portal uses CSRF token for state-changing requests (POST/PATCH/DELETE)
```

---

## Token Lifecycle

### Access Token (3-minute expiry)

- Issued via `/auth/github/callback` or `/auth/refresh`
- Used as Bearer token in `Authorization` header
- Can also be read from `access_token` cookie
- Checked on every `/api/*` request

### Refresh Token (5-minute expiry)

- Returned only via `/auth/github/callback` or `/auth/refresh`
- Stored securely in database with SHA256 hash
- Single-use: `POST /auth/refresh` invalidates old token and returns new pair
- Old refresh token cannot be reused

### Token Expiry Detection

**CLI:**
- If access token expired, auto-refresh using refresh token
- If refresh token expired, prompt user to re-login

**Portal:**
- Access token cookie auto-refreshed via `POST /auth/refresh`
- Refresh cookie updated silently
- Manual re-login required if refresh expired

---

## API Versioning

All profile endpoints require the `X-API-Version: 1` header.

Missing header returns:
```json
{
  "status": "error",
  "message": "API version header required"
}
```

---

## Pagination

All list endpoints (`GET /api/profiles`, `GET /api/profiles/search`) now return:

```json
{
  "status": "success",
  "page": 1,
  "limit": 10,
  "total": 2026,
  "total_pages": 203,
  "links": {
    "self": "/api/profiles?page=1&limit=10",
    "next": "/api/profiles?page=2&limit=10",
    "prev": null
  },
  "data": [...]
}
```

---

## Role-Based Access Control

### Admin (`role = "admin"`)

- Full access to all endpoints
- Can create profiles: `POST /api/profiles`
- Can delete profiles: `DELETE /api/profiles/{id}`
- Can manage users: `GET/PATCH /api/admin/users/*`

### Analyst (`role = "analyst"`, default)

- Read-only access
- Can list profiles: `GET /api/profiles`
- Can search profiles: `GET /api/profiles/search`
- Can get single profile: `GET /api/profiles/{id}`
- Can export CSV: `GET /api/profiles/export?format=csv`
- Cannot create or delete profiles

### Admin-Only Endpoints

- `GET /api/admin/users` — List all users
- `PATCH /api/admin/users/{id}/role` — Change user role
- `PATCH /api/admin/users/{id}/status` — Deactivate user (is_active=false)

---

## Rate Limiting

**Per-minute limits** enforced globally:

| Endpoint | Limit |
|---|---|
| `/auth/*` | 10 requests/minute (by IP) |
| Other `/api/*` | 60 requests/minute (by user ID) |

Exceeding limit returns:
```json
{
  "status": "error",
  "message": "Too many requests"
}
```

HTTP status: `429 Too Many Requests`

---

## Logging

Every request is logged to `logs/requests.log` in format:

```
[2026-04-28T12:34:56Z] GET /api/profiles 200 45ms
```

Contains: timestamp, method, path, status code, response time.

---

## Natural Language Parsing

The `/api/profiles/search?q=<query>` endpoint parses plain English into filters.

### Supported Keywords

**Gender:**
- `male`, `men`, `man` → `gender=male`
- `female`, `women`, `woman`, `girl` → `gender=female`

**Age Group:**
- `child`, `children`, `kids` → `age_group=child`
- `teen`, `teenager`, `adolescent` → `age_group=teenager`
- `adult` → `age_group=adult`
- `senior`, `elderly`, `old people` → `age_group=senior`

**Age Range:**
- `young` → `min_age=16&max_age=24`
- `above 30` → `min_age=30`
- `below 40` → `max_age=40`
- `between 25 and 35` → `min_age=25&max_age=35`

**Country:**
- Full name: `from Nigeria` → `country_id=NG`
- ISO code: `from NG` → `country_id=NG`

### Examples

```
GET /api/profiles/search?q=young+males+from+nigeria
Parsed: gender=male, min_age=16, max_age=24, country_id=NG

GET /api/profiles/search?q=female+adults+from+kenya
Parsed: gender=female, age_group=adult, country_id=KE
```

---

## CSV Export

`GET /api/profiles/export?format=csv`

Applies same filters as `GET /api/profiles` (gender, country, age range, etc.).

Returns:
- Content-Type: `text/csv`
- Content-Disposition: `attachment; filename="profiles_YYYYMMDD_HHMMSS.csv"`

**Columns (in order):**
```
id, name, gender, gender_probability, age, age_group, country_id, country_name, country_probability, created_at
```

---

## Seeded Users

On first run, the following user is created:

| Email | Role | Status |
|---|---|---|
| akoshodi@gmail.com | admin | active |

All other OAuth users default to `analyst` role.

**Assigning Roles:**

Admin can promote/demote users via:
```bash
curl -X PATCH https://profiles-api.duckdns.org/api/admin/users/{user_id}/role \
  -H "Authorization: Bearer <admin_access_token>" \
  -H "X-API-Version: 1" \
  -H "Content-Type: application/json" \
  -d '{"role": "admin"}'
```

---

## Endpoints Summary

### Authentication

| Method | Path | Auth | Description |
|---|---|---|---|
| GET | `/auth/github` | public | Initiate GitHub OAuth |
| GET | `/auth/github/callback` | public | OAuth callback (web) |
| POST | `/auth/github/exchange` | public | Exchange code for tokens (CLI) |
| POST | `/auth/refresh` | any | Rotate token pair |
| POST | `/auth/logout` | any | Revoke tokens |

### User

| Method | Path | Auth | Description |
|---|---|---|---|
| GET | `/api/me` | required | Get current user |

### Profiles (Stage 2 features preserved)

| Method | Path | Auth | Role | Description |
|---|---|---|---|---|
| GET | `/api/profiles` | required | any | List with filters/sort/pagination |
| POST | `/api/profiles` | required | admin | Create profile |
| GET | `/api/profiles/search` | required | any | Natural language search |
| GET | `/api/profiles/{id}` | required | any | Get single profile |
| GET | `/api/profiles/export` | required | any | Export as CSV |
| DELETE | `/api/profiles/{id}` | required | admin | Delete profile |

### Admin

| Method | Path | Auth | Role | Description |
|---|---|---|---|---|
| GET | `/api/admin/users` | required | admin | List all users |
| PATCH | `/api/admin/users/{id}/role` | required | admin | Change user role |
| PATCH | `/api/admin/users/{id}/status` | required | admin | Deactivate user |

---

## Running Locally

```bash
# Install dependencies
composer install

# Configure .env
cp .env.example .env
# Edit .env with GitHub OAuth credentials

# Seed database and start server
php -S localhost:8000 -t public

# Test
curl http://localhost:8000/api/profiles
# Returns 401 (auth required)
```

---

## CLI Setup

```bash
# Build Go CLI
cd ../insighta-cli
go build -o insighta ./cmd/insighta

# Install globally
go install ./cmd/insighta

# Configure backend
export INSIGHTA_API_BASE_URL=https://profiles-api.duckdns.org

# Login (opens browser)
insighta login

# Use
insighta profiles list
insighta profiles search "young males from nigeria"
insighta profiles export --format csv
```

---

## Web Portal Setup

```bash
# Install dependencies
cd ../insighta-web-portal
npm install

# Configure
export NEXT_PUBLIC_API_BASE_URL=https://profiles-api.duckdns.org

# Run
npm run dev
# Portal at http://localhost:3000
```

---

## Error Responses

All errors follow standard format:

```json
{
  "status": "error",
  "message": "<human-readable message>"
}
```

Common HTTP status codes:
- `400` Bad Request — invalid parameters, missing required fields
- `401` Unauthorized — missing or invalid auth token
- `403` Forbidden — insufficient permissions (role check failed)
- `404` Not Found — resource does not exist
- `422` Unprocessable Entity — type validation failed
- `429` Too Many Requests — rate limit exceeded
- `500` Internal Server Error — server error

---

## CI/CD

GitHub Actions workflows in `.github/workflows/`:
- `lint.yml` — PHP linting
- `test.yml` — Unit tests
- `build.yml` — Build checks

---

## Submission

**Three repositories submitted:**

1. [profiles-api](https://github.com/akoshodi/profiles-api) — Backend
2. [insighta-cli](https://github.com/akoshodi/insighta-cli) — CLI
3. [insighta-web-portal](https://github.com/akoshodi/insighta-web-portal) — Web Portal

**Live deployments:**
- Backend: `https://profiles-api.duckdns.org`
- Web Portal: `https://insighta.example.com` (pending)
