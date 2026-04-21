# Profiles API — Stage 2

A demographic intelligence REST API built with Slim PHP 4. Accepts names, enriches them via three external classification APIs (Genderize, Agify, Nationalize), persists profiles to SQLite, and exposes endpoints for advanced filtering, sorting, pagination, and natural language querying.

---

## Live API

**Base URL**
```
https://profiles-api.duckdns.org
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
| Server | Nginx + PHP 8.4-FPM on Ubuntu |
| DNS | DuckDNS + Let's Encrypt SSL |

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

Accepts a name, calls all three external APIs, stores and returns the profile. Idempotent — submitting the same name twice returns the existing profile.

**Request Body**
```json
{ "name": "ella" }
```

**Success — `201 Created`**
```json
{
  "status": "success",
  "data": {
    "id": "019571a2-1c3b-7a4e-9f2d-3b8c1e5d7f90",
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

**Already exists — `200 OK`**
```json
{
  "status": "success",
  "message": "Profile already exists",
  "data": { "...existing profile..." }
}
```

---

### 2. `GET /api/profiles`

Returns all profiles with support for filtering, sorting, and pagination in a single request.

**Query Parameters**

| Parameter | Type | Description |
|---|---|---|
| `gender` | string | Filter by `male` or `female` |
| `age_group` | string | Filter by `child`, `teenager`, `adult`, `senior` |
| `country_id` | string | Filter by ISO country code e.g. `NG`, `KE` |
| `min_age` | integer | Minimum age (inclusive) |
| `max_age` | integer | Maximum age (inclusive) |
| `min_gender_probability` | float | Minimum gender confidence score |
| `min_country_probability` | float | Minimum country confidence score |
| `sort_by` | string | Sort field: `age`, `created_at`, `gender_probability` (default: `created_at`) |
| `order` | string | Sort direction: `asc` or `desc` (default: `desc`) |
| `page` | integer | Page number (default: `1`) |
| `limit` | integer | Results per page (default: `10`, max: `50`) |

All filters are combinable. Results match every condition passed. All string filters are case-insensitive.

**Example**
```
GET /api/profiles?gender=male&country_id=NG&min_age=25&sort_by=age&order=desc&page=1&limit=10
```

**Success — `200 OK`**
```json
{
  "status": "success",
  "page": 1,
  "limit": 10,
  "total": 284,
  "data": [
    {
      "id": "019571a2-1c3b-7a4e-9f2d-3b8c1e5d7f90",
      "name": "emmanuel",
      "gender": "male",
      "gender_probability": 0.99,
      "age": 34,
      "age_group": "adult",
      "country_id": "NG",
      "country_name": "Nigeria",
      "country_probability": 0.85,
      "created_at": "2026-04-13T10:00:00Z"
    }
  ]
}
```

---

### 3. `GET /api/profiles/search`

Natural language query endpoint. Parses a plain English query string and converts it into database filters. Supports the same `page` and `limit` pagination parameters as the main listing endpoint.

**Query Parameters**

| Parameter | Type | Required | Description |
|---|---|---|---|
| `q` | string | Yes | Plain English search query |
| `page` | integer | No | Page number (default: `1`) |
| `limit` | integer | No | Results per page (default: `10`, max: `50`) |

**Example**
```
GET /api/profiles/search?q=young males from nigeria&page=1&limit=10
```

**Success — `200 OK`**
```json
{
  "status": "success",
  "page": 1,
  "limit": 10,
  "total": 12,
  "data": [ "..." ]
}
```

**Uninterpretable query — `400`**
```json
{
  "status": "error",
  "message": "Unable to interpret query"
}
```

---

### 4. `GET /api/profiles/{id}`

Returns a single profile by UUID.

**Success — `200 OK`**
```json
{
  "status": "success",
  "data": {
    "id": "019571a2-1c3b-7a4e-9f2d-3b8c1e5d7f90",
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

### 5. `DELETE /api/profiles/{id}`

Deletes a profile by UUID.

**Success — `204 No Content`** (no body)

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

The country with the highest probability is selected as `country_id` and mapped to a full `country_name`.

---

## Natural Language Parsing

The `/api/profiles/search` endpoint uses a **rule-based parser** — no AI or LLMs. It applies ordered regex patterns and keyword lookups to extract filter intent from plain English queries.

### How It Works

The parser lowercases the query and applies pattern matching in this order:

1. **Gender detection** — matches keywords, maps to `gender` filter
2. **Age group detection** — matches group keywords, maps to `age_group` filter
3. **"young" keyword** — maps to `min_age=16` + `max_age=24` (not a stored age group)
4. **Explicit age comparisons** — extracts numeric ages from phrases, maps to `min_age` / `max_age`
5. **Country detection** — matches full country names (longest match first) or bare ISO codes, maps to `country_id`

If none of these patterns match and no filters are extracted, the parser throws and the endpoint returns `"Unable to interpret query"`.

### Supported Keywords and Mappings

**Gender**

| Query contains | Maps to |
|---|---|
| `male`, `men`, `man` | `gender=male` |
| `female`, `women`, `woman`, `girl`, `girls` | `gender=female` |
| `male and female`, `both genders` | no gender filter (all genders) |

**Age Group**

| Query contains | Maps to |
|---|---|
| `child`, `children`, `kids` | `age_group=child` |
| `teen`, `teenager`, `teenagers`, `adolescents` | `age_group=teenager` |
| `adult`, `adults` | `age_group=adult` |
| `senior`, `seniors`, `elderly`, `old people` | `age_group=senior` |

**Age Range Keywords**

| Query pattern | Maps to |
|---|---|
| `young` | `min_age=16`, `max_age=24` |
| `above X`, `over X`, `older than X` | `min_age=X` |
| `below X`, `under X`, `younger than X` | `max_age=X` |
| `between X and Y` | `min_age=X`, `max_age=Y` |
| `aged X`, `age X` | `min_age=X`, `max_age=X` |

**Country**

Full country names are matched (e.g. `nigeria`, `south africa`, `ivory coast`). Multi-word names are matched before single-word names to avoid partial matches. Bare ISO codes after `from` or `in` are also accepted (e.g. `from NG`).

Supported countries include all major African nations plus US, UK, France, Germany, India, Brazil, China, Japan, Russia, and 50+ others. See `src/Parsers/NaturalLanguageParser.php` for the full list.

### Example Query Mappings

| Query | Extracted Filters |
|---|---|
| `young males from nigeria` | `gender=male`, `min_age=16`, `max_age=24`, `country_id=NG` |
| `females above 30` | `gender=female`, `min_age=30` |
| `people from angola` | `country_id=AO` |
| `adult males from kenya` | `gender=male`, `age_group=adult`, `country_id=KE` |
| `male and female teenagers above 17` | `age_group=teenager`, `min_age=17` |
| `seniors in south africa` | `age_group=senior`, `country_id=ZA` |
| `women between 20 and 35` | `gender=female`, `min_age=20`, `max_age=35` |

### Parser Limitations

The following cases are known gaps and are not handled:

- **Negation** — queries like `not from nigeria` or `excluding males` are not supported. The parser has no negation logic.
- **OR conditions** — `males from nigeria or ghana` is not supported. Only AND logic is applied across all filters.
- **Multiple countries** — only the first matched country is used. `people from nigeria and kenya` resolves to Nigeria only.
- **Relative age terms beyond "young"** — words like `old`, `middle-aged`, `mature` are not mapped to any filter.
- **Name-based search** — searching by partial name (e.g. `profiles named john`) is not supported.
- **Probability filters** — natural language cannot yet express `highly confident predictions` as a `min_gender_probability` filter.
- **Sorting intent** — phrases like `youngest males` or `sorted by age` are not parsed into sort parameters.
- **Typos and abbreviations** — `nigerian`, `kenyan`, `egyptian` (adjective forms) are not mapped. Only noun forms work.
- **Unknown countries** — countries not in the parser's map will not match even if spelled correctly.

---

## Error Responses

All errors follow this structure:

```json
{ "status": "error", "message": "<description>" }
```

| Status Code | Reason |
|---|---|
| `400 Bad Request` | Missing or empty required parameter |
| `400 Bad Request` | Invalid `sort_by` or `order` value |
| `400 Bad Request` | Query cannot be interpreted |
| `422 Unprocessable Entity` | Parameter is wrong type |
| `404 Not Found` | Profile ID does not exist |
| `502 Bad Gateway` | External API returned invalid response |
| `500 Internal Server Error` | Unexpected server failure |

**Edge Cases That Trigger 502**
- Genderize returns `gender: null` or `count: 0`
- Agify returns `age: null`
- Nationalize returns an empty country array

---

## Database

SQLite database with the following schema:

| Field | Type | Notes |
|---|---|---|
| `id` | TEXT | UUID v7, primary key |
| `name` | TEXT | Unique, lowercased |
| `gender` | TEXT | `male` or `female` |
| `gender_probability` | REAL | 0–1 confidence score |
| `age` | INTEGER | Predicted age |
| `age_group` | TEXT | `child`, `teenager`, `adult`, `senior` |
| `country_id` | TEXT | ISO 3166-1 alpha-2 code |
| `country_name` | TEXT | Full country name |
| `country_probability` | REAL | 0–1 confidence score |
| `created_at` | TEXT | UTC ISO 8601 timestamp |

Indexes are applied on `gender`, `age_group`, `country_id`, `age`, and `created_at` to avoid full table scans on filtered queries.

---

## Data Seeding

The database is pre-seeded with 2026 profiles. Re-running the seed is safe — duplicates are skipped via `INSERT OR IGNORE`.

```bash
php scripts/seed.php
# Seed complete: 2026 inserted, 0 skipped (duplicates).
```

---

## Quick Test

```bash
# Create a profile
curl -X POST https://profiles-api.duckdns.org/api/profiles \
  -H "Content-Type: application/json" \
  -d '{"name": "ella"}'

# Get all profiles with pagination
curl "https://profiles-api.duckdns.org/api/profiles?page=1&limit=5"

# Combined filters with sorting
curl "https://profiles-api.duckdns.org/api/profiles?gender=male&country_id=NG&min_age=25&sort_by=age&order=desc"

# Natural language search
curl "https://profiles-api.duckdns.org/api/profiles/search?q=young%20males%20from%20nigeria"
curl "https://profiles-api.duckdns.org/api/profiles/search?q=females%20above%2030"
curl "https://profiles-api.duckdns.org/api/profiles/search?q=adult%20males%20from%20kenya"

# Get single profile
curl "https://profiles-api.duckdns.org/api/profiles/YOUR-UUID-HERE"

# Delete a profile
curl -X DELETE "https://profiles-api.duckdns.org/api/profiles/YOUR-UUID-HERE"
```

---

## Local Setup

### Prerequisites

- PHP 8.2+
- Composer

### Installation

```bash
git clone https://github.com/yourusername/profiles-api.git
cd profiles-api
composer install
cp .env.example .env
```

### Environment Variables

| Variable | Description | Default |
|---|---|---|
| `DB_PATH` | SQLite database file path | `database/profiles.db` |
| `GENDERIZE_URL` | Genderize API base URL | `https://api.genderize.io` |
| `AGIFY_URL` | Agify API base URL | `https://api.agify.io` |
| `NATIONALIZE_URL` | Nationalize API base URL | `https://api.nationalize.io` |

### Run Locally

```bash
php -S localhost:8080 -t public
```

### Seed the Database

```bash
php scripts/seed.php
```

---

## Project Structure

```
profiles-api/
├── src/
│   ├── Services/
│   │   ├── GenderizeService.php       # Gender classification via Genderize API
│   │   ├── AgifyService.php           # Age classification via Agify API
│   │   └── NationalizeService.php     # Nationality classification + country name map
│   ├── Parsers/
│   │   └── NaturalLanguageParser.php  # Rule-based NLP query parser
│   └── Database.php                   # PDO SQLite connection, migration, indexes
├── scripts/
│   └── seed.php                       # Idempotent database seeder
├── data/
│   └── profiles.json                  # 2026 seed profiles
├── public/
│   └── index.php                      # App bootstrap, all routes and middleware
├── composer.json
├── nixpacks.toml
├── railway.json
├── .env.example
└── README.md
```

---

## Deployment

Deployed on Ubuntu with Nginx + PHP 8.4-FPM. SSL via Let's Encrypt. DNS via DuckDNS.

### Deploy Updates

```bash
# On the server
cd /var/www/profiles-api
git fetch origin
git reset --hard origin/main
composer install --no-dev --optimize-autoloader
php scripts/seed.php
sudo chown -R www-data:www-data /var/www/profiles-api
sudo systemctl restart php8.4-fpm
```
