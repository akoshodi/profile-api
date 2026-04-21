# Profiles API

A REST API built with Slim PHP 4 that accepts a name, enriches it by calling three external classification APIs (Genderize, Agify, Nationalize), stores the result in a SQLite database, and exposes endpoints to create, retrieve, filter, and delete profiles.

---

## Live API

**Base URL**
```
https://handsome-cat-production.up.railway.app
```

---

## Tech Stack

| Layer | Technology |
|---|---|
| Framework | Slim PHP 4 |
| Database | SQLite (via PDO) |
| HTTP Client | Guzzle 7 |
| DI Container | PHP-DI 7 |
| Environment | vlucas/phpdotenv |
| IDs | UUID v7 (ramsey/uuid) |
| Platform | Railway |
| PHP Version | 8.2 |

---

## External APIs Used

| API | Purpose | URL |
|---|---|---|
| Genderize | Predicts gender from name | https://api.genderize.io |
| Agify | Predicts age from name | https://api.agify.io |
| Nationalize | Predicts nationality from name | https://api.nationalize.io |

---

## Endpoints

### 1. `POST /api/profiles`

Accepts a name, calls all three external APIs, applies classification logic, stores and returns the profile.

Idempotent — if the same name is submitted again, the existing profile is returned without creating a duplicate.

**Request Body**
```json
{ "name": "ella" }
```

**Success Response — `201 Created`**
```json
{
  "status": "success",
  "data": {
    "id": "b3f9c1e2-7d4a-4c91-9c2a-1f0a8e5b6d12",
    "name": "ella",
    "gender": "female",
    "gender_probability": 0.99,
    "age": 46,
    "age_group": "adult",
    "country_id": "DK",
    "country_name": "Denmark",
    "country_probability": 0.85,
    "created_at": "2026-04-13T10:00:00Z"
  }
}
```

**Already Exists Response — `200 OK`**
```json
{
  "status": "success",
  "message": "Profile already exists",
  "data": { "...existing profile..." }
}
```

---

### 2. `GET /api/profiles`

Returns stored profiles with advanced filtering, sorting, and pagination.

**Query Parameters**

| Parameter | Type | Description |
|---|---|---|
| `gender` | string | Filter by gender (e.g. `male`, `female`) |
| `age_group` | string | Filter by age group (`child`, `teenager`, `adult`, `senior`) |
| `country_id` | string | Filter by country code (e.g. `NG`, `US`) |
| `min_age` | integer | Minimum age (inclusive) |
| `max_age` | integer | Maximum age (inclusive) |
| `min_gender_probability` | float | Minimum gender confidence |
| `min_country_probability` | float | Minimum country confidence |
| `sort_by` | string | `age` \| `created_at` \| `gender_probability` |
| `order` | string | `asc` \| `desc` |
| `page` | integer | Pagination page (default `1`) |
| `limit` | integer | Page size (default `10`, max `50`) |

**Example**
```
GET /api/profiles?gender=male&country_id=NG&min_age=25&sort_by=age&order=desc&page=1&limit=10
```

**Success Response — `200 OK`**
```json
{
  "status": "success",
  "page": 1,
  "limit": 10,
  "total": 2026,
  "data": [
    {
      "id": "b3f9c1e2-7d4a-4c91-9c2a-1f0a8e5b6d12",
      "name": "emmanuel",
      "gender": "male",
      "gender_probability": 0.99,
      "age": 34,
      "age_group": "adult",
      "country_id": "NG",
      "country_name": "Nigeria",
      "country_probability": 0.85,
      "created_at": "2026-04-01T12:00:00Z"
    }
  ]
}
```

---

### 2b. `GET /api/profiles/search`

Natural language query endpoint that parses plain-English text into the same filter model used by `GET /api/profiles`.

**Query Parameters**

| Parameter | Type | Description |
|---|---|---|
| `q` | string | Natural language query text (required) |
| `page` | integer | Pagination page (default `1`) |
| `limit` | integer | Page size (default `10`, max `50`) |

**Example**
```
GET /api/profiles/search?q=young males from nigeria&page=1&limit=10
```

---

### 3. `GET /api/profiles/{id}`

Returns a single profile by UUID.

**Success Response — `200 OK`**
```json
{
  "status": "success",
  "data": {
    "id": "b3f9c1e2-7d4a-4c91-9c2a-1f0a8e5b6d12",
    "name": "emmanuel",
    "gender": "male",
    "gender_probability": 0.99,
    "age": 25,
    "age_group": "adult",
    "country_id": "NG",
    "country_name": "Nigeria",
    "country_probability": 0.85,
    "created_at": "2026-04-13T10:00:00Z"
  }
}
```

---

### 4. `DELETE /api/profiles/{id}`

Deletes a profile by UUID. Returns no body on success.

**Success Response — `204 No Content`**

---

## Classification Rules

**Age Group (from Agify)**

| Age Range | Group |
|---|---|
| 0 – 12 | `child` |
| 13 – 19 | `teenager` |
| 20 – 59 | `adult` |
| 60+ | `senior` |

**Nationality (from Nationalize)**

The country with the highest probability in the response is selected as `country_id`.

---

## Natural Language Parsing Approach

The parser is fully rule-based (no AI/LLM) and lives in `src/Parsers/NaturalLanguageParser.php`.

### 1) Normalization

- Query is lowercased and trimmed.
- Matching is done using regex and keyword lookup.

### 2) Supported Keywords and Mappings

**Gender mapping**

| Query words | Filter output |
|---|---|
| `male`, `man`, `men` | `gender=male` |
| `female`, `woman`, `women`, `girl`, `girls` | `gender=female` |
| `male and female`, `both genders`, `all genders` | no gender filter |

**Age-group mapping**

| Query words | Filter output |
|---|---|
| `child`, `children`, `kid`, `kids` | `age_group=child` |
| `teen`, `teenager`, `teenagers`, `adolescent`, `adolescents` | `age_group=teenager` |
| `adult`, `adults` | `age_group=adult` |
| `senior`, `seniors`, `elderly`, `old people` | `age_group=senior` |

**Special age phrase**

| Query words | Filter output |
|---|---|
| `young` | `min_age=16`, `max_age=24` |

**Numeric age conditions**

| Query pattern | Filter output |
|---|---|
| `above X`, `over X`, `older than X`, `greater than X` | `min_age=X` |
| `below X`, `under X`, `younger than X`, `less than X` | `max_age=X` |
| `between X and Y` | `min_age=X`, `max_age=Y` |
| `aged X`, `age X` | `min_age=X`, `max_age=X` |

**Country mapping**

- Country names are mapped to ISO-2 codes through a static dictionary.
- Multi-word countries are matched first (for example, `south africa` before shorter tokens).
- Bare ISO codes are also accepted in context phrases such as `from NG` or `in KE`.

### 3) Parse Result and Error Behavior

- The parser emits a filter array used directly by SQL query builder logic.
- If no interpretable pattern is found, the endpoint returns:

```json
{ "status": "error", "message": "Unable to interpret query" }
```

---

## Natural Language Parser Limitations

- No typo-tolerance or fuzzy matching (`nigeira` will not match `nigeria`).
- No multilingual parsing (English-only keyword rules).
- No comparative intent outside supported templates (for example, `old but not too old`).
- No nested logical grouping (`(males from NG) or (females from KE)`) beyond simple token coexistence.
- Only one country filter is emitted (first best keyword/code match).
- Does not infer ambiguous slang or context-heavy phrases.

These constraints are intentional to keep behavior deterministic and fully explainable.

---

## Error Responses

All errors follow this structure:

```json
{ "status": "error", "message": "<description>" }
```

| Status Code | Reason |
|---|---|
| `400 Bad Request` | `name` field is missing or empty |
| `422 Unprocessable Entity` | `name` is not a string |
| `404 Not Found` | Profile with the given ID does not exist |
| `502 Bad Gateway` | An external API returned an invalid or empty response |
| `500 Internal Server Error` | Unexpected server-side failure |

**502 Error Format**
```json
{ "status": "error", "message": "Genderize returned an invalid response" }
```

The API name in the message will be one of: `Genderize`, `Agify`, or `Nationalize`.

**Edge Cases That Trigger 502**
- Genderize returns `gender: null` or `count: 0`
- Agify returns `age: null`
- Nationalize returns an empty country array

When any of these occur, no profile is stored.

---

## Quick Test

```bash
# Create a profile
curl -X POST https://handsome-cat-production.up.railway.app/api/profiles \
  -H "Content-Type: application/json" \
  -d '{"name": "ella"}'

# Idempotency — same name returns existing profile
curl -X POST https://handsome-cat-production.up.railway.app/api/profiles \
  -H "Content-Type: application/json" \
  -d '{"name": "ella"}'

# Get all profiles
curl https://handsome-cat-production.up.railway.app/api/profiles

# Filter by gender and country
curl "https://handsome-cat-production.up.railway.app/api/profiles?gender=female&country_id=NG"

# Get single profile (replace with a real ID)
curl https://handsome-cat-production.up.railway.app/api/profiles/YOUR-UUID-HERE

# Delete a profile
curl -X DELETE https://handsome-cat-production.up.railway.app/api/profiles/YOUR-UUID-HERE

# Error cases
curl -X POST https://handsome-cat-production.up.railway.app/api/profiles \
  -H "Content-Type: application/json" \
  -d '{}'

curl -X POST https://handsome-cat-production.up.railway.app/api/profiles \
  -H "Content-Type: application/json" \
  -d '{"name": ""}'
```

---

## Local Setup

### Prerequisites

- PHP 8.2+
- Composer

### Installation

```bash
# Clone the repository
git clone https://github.com/yourusername/profiles-api.git
cd profiles-api

# Install dependencies
composer install

# Copy environment file
cp .env.example .env
```

### Environment Variables

| Variable | Description | Default |
|---|---|---|
| `DB_PATH` | Path to the SQLite database file | `/app/database/profiles.db` |
| `GENDERIZE_URL` | Genderize API base URL | `https://api.genderize.io` |
| `AGIFY_URL` | Agify API base URL | `https://api.agify.io` |
| `NATIONALIZE_URL` | Nationalize API base URL | `https://api.nationalize.io` |

### Run Locally

```bash
php -S localhost:8080 -t public
```

The API is now available at `http://localhost:8080`.

---

## Project Structure

```
profiles-api/
├── src/
│   ├── Services/
│   │   ├── GenderizeService.php      # Gender classification via Genderize API
│   │   ├── AgifyService.php          # Age classification via Agify API
│   │   └── NationalizeService.php    # Nationality classification via Nationalize API
│   └── Database.php                  # PDO SQLite connection and schema migration
├── public/
│   └── index.php                     # App bootstrap, all routes and middleware
├── composer.json                     # Dependencies and PSR-4 autoload config
├── nixpacks.toml                     # Railway Nixpacks build configuration
├── railway.json                      # Railway deployment configuration
├── .env                              # Local environment variables (not committed)
├── .env.example                      # Environment variable template
└── README.md
```

---

## Deployment

Deployed on [Railway](https://railway.app) with automatic deploys triggered by pushes to `main`.

### Deploy Your Own

```bash
# Install Railway CLI
npm install -g @railway/cli

# Login and link
railway login
railway link

# Deploy
railway up
```

Or connect your GitHub repo in the Railway dashboard for automatic deploys on every push — no CLI needed.

Set all environment variables under **Variables** in the Railway service dashboard before deploying.
