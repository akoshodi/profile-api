#!/usr/bin/env python3
"""
Comprehensive endpoint test suite for Profiles API Stage 2 - GCP Deployment.
Focuses on functionality that doesn't require external API calls.
"""

import requests
import json
import sys
from typing import Any, Dict, List
from urllib.parse import urljoin

BASE_URL = "https://profiles-api.duckdns.org"
HEADERS = {"Content-Type": "application/json", "Accept": "application/json"}

# Test results tracking
passed = 0
failed = 0
errors: List[str] = []


def test_result(name: str, condition: bool, details: str = "") -> None:
    """Record test result."""
    global passed, failed, errors
    if condition:
        passed += 1
        print(f"✓ {name}")
    else:
        failed += 1
        print(f"✗ {name}")
        if details:
            errors.append(f"{name}: {details}")


def assert_json_valid(response_text: str, test_name: str) -> Dict[str, Any] | None:
    """Validate response is valid JSON and return parsed object."""
    try:
        return json.loads(response_text)
    except json.JSONDecodeError as e:
        test_result(test_name, False, f"Invalid JSON: {str(e)}")
        return None


def assert_status_code(response: requests.Response, expected: int, test_name: str) -> bool:
    """Check HTTP status code."""
    condition = response.status_code == expected
    test_result(
        test_name,
        condition,
        f"Expected {expected}, got {response.status_code}",
    )
    return condition


def assert_response_structure(
    data: Dict[str, Any], required_fields: List[str], test_name: str
) -> bool:
    """Check response contains all required fields."""
    missing = [f for f in required_fields if f not in data]
    condition = len(missing) == 0
    test_result(
        test_name,
        condition,
        f"Missing fields: {missing}" if missing else "",
    )
    return condition


# ============================================================================
# TEST SUITE
# ============================================================================


def test_get_profiles_default() -> None:
    """Test GET /api/profiles with default pagination."""
    print("\n[GET /api/profiles - Default]")
    url = urljoin(BASE_URL, "/api/profiles")
    r = requests.get(url, headers=HEADERS, timeout=10)
    
    assert_status_code(r, 200, "HTTP 200 status")
    data = assert_json_valid(r.text, "Valid JSON response")
    if data:
        assert_response_structure(
            data, ["status", "page", "limit", "total", "data"], "Response structure"
        )
        test_result("status=success", data.get("status") == "success")
        test_result("page=1 (default)", data.get("page") == 1)
        test_result("limit=10 (default)", data.get("limit") == 10)
        test_result("total > 0", data.get("total", 0) > 0)
        test_result("data is array", isinstance(data.get("data"), list))


def test_get_profiles_combined_filters() -> None:
    """Test GET /api/profiles with combined filters, sort, and pagination."""
    print("\n[GET /api/profiles - Combined Filters]")
    url = urljoin(
        BASE_URL,
        "/api/profiles?gender=male&country_id=NG&min_age=25&sort_by=age&order=desc&page=1&limit=5",
    )
    r = requests.get(url, headers=HEADERS, timeout=10)
    
    assert_status_code(r, 200, "HTTP 200 status")
    data = assert_json_valid(r.text, "Valid JSON response")
    if data:
        assert_response_structure(data, ["status", "page", "limit", "total", "data"], "Response structure")
        test_result("Results filtered", len(data.get("data", [])) > 0)
        if data.get("data"):
            first = data["data"][0]
            test_result("gender=male", first.get("gender") == "male")
            test_result("country_id=NG", first.get("country_id") == "NG")
            test_result("age >= 25", first.get("age", 0) >= 25)


def test_get_profiles_sorting_desc() -> None:
    """Test GET /api/profiles sorting by age descending."""
    print("\n[GET /api/profiles - Sorting]")
    url = urljoin(BASE_URL, "/api/profiles?sort_by=age&order=desc&limit=10")
    r = requests.get(url, headers=HEADERS, timeout=10)
    
    assert_status_code(r, 200, "HTTP 200 status")
    data = assert_json_valid(r.text, "Valid JSON response")
    if data:
        profiles = data.get("data", [])
        if len(profiles) >= 2:
            # Verify descending order
            ages = [p.get("age", 0) for p in profiles]
            is_sorted = all(ages[i] >= ages[i+1] for i in range(len(ages)-1))
            test_result("Results sorted by age descending", is_sorted)


def test_get_profiles_invalid_sort() -> None:
    """Test GET /api/profiles with invalid sort_by parameter."""
    print("\n[GET /api/profiles - Invalid sort_by]")
    url = urljoin(BASE_URL, "/api/profiles?sort_by=name")
    r = requests.get(url, headers=HEADERS, timeout=10)
    
    assert_status_code(r, 400, "HTTP 400 status")
    data = assert_json_valid(r.text, "Valid JSON response")
    if data:
        test_result("error status", data.get("status") == "error")
        test_result("Invalid query parameters message", "Invalid query parameters" in data.get("message", ""))


def test_get_profiles_invalid_order() -> None:
    """Test GET /api/profiles with invalid order parameter."""
    print("\n[GET /api/profiles - Invalid order]")
    url = urljoin(BASE_URL, "/api/profiles?order=up")
    r = requests.get(url, headers=HEADERS, timeout=10)
    
    assert_status_code(r, 400, "HTTP 400 status")
    data = assert_json_valid(r.text, "Valid JSON response")
    if data:
        test_result("error status", data.get("status") == "error")


def test_get_profiles_invalid_numeric_type() -> None:
    """Test GET /api/profiles with non-numeric min_age."""
    print("\n[GET /api/profiles - Invalid numeric type]")
    url = urljoin(BASE_URL, "/api/profiles?min_age=abc")
    r = requests.get(url, headers=HEADERS, timeout=10)
    
    assert_status_code(r, 422, "HTTP 422 status")
    data = assert_json_valid(r.text, "Valid JSON response")
    if data:
        test_result("error status", data.get("status") == "error")
        test_result("Invalid parameter type message", "Invalid parameter type" in data.get("message", ""))


def test_get_profiles_unknown_param() -> None:
    """Test GET /api/profiles with unknown query parameter."""
    print("\n[GET /api/profiles - Unknown parameter]")
    url = urljoin(BASE_URL, "/api/profiles?foo=bar")
    r = requests.get(url, headers=HEADERS, timeout=10)
    
    assert_status_code(r, 400, "HTTP 400 status")
    data = assert_json_valid(r.text, "Valid JSON response")
    if data:
        test_result("error status", data.get("status") == "error")


def test_get_profiles_pagination_limit_cap() -> None:
    """Test GET /api/profiles with limit exceeding maximum."""
    print("\n[GET /api/profiles - Pagination limit cap]")
    url = urljoin(BASE_URL, "/api/profiles?limit=200")
    r = requests.get(url, headers=HEADERS, timeout=10)
    
    assert_status_code(r, 200, "HTTP 200 status")
    data = assert_json_valid(r.text, "Valid JSON response")
    if data:
        test_result("limit capped to 50", data.get("limit") == 50)


def test_get_profiles_by_age_group() -> None:
    """Test GET /api/profiles filtered by age_group."""
    print("\n[GET /api/profiles - Filter by age_group]")
    url = urljoin(BASE_URL, "/api/profiles?age_group=adult&limit=5")
    r = requests.get(url, headers=HEADERS, timeout=10)
    
    assert_status_code(r, 200, "HTTP 200 status")
    data = assert_json_valid(r.text, "Valid JSON response")
    if data:
        test_result("Results filtered", len(data.get("data", [])) > 0)
        if data.get("data"):
            for profile in data["data"]:
                test_result("age_group=adult", profile.get("age_group") == "adult")
                break


def test_get_profiles_by_country() -> None:
    """Test GET /api/profiles filtered by country_id."""
    print("\n[GET /api/profiles - Filter by country]")
    url = urljoin(BASE_URL, "/api/profiles?country_id=US&limit=5")
    r = requests.get(url, headers=HEADERS, timeout=10)
    
    assert_status_code(r, 200, "HTTP 200 status")
    data = assert_json_valid(r.text, "Valid JSON response")
    if data:
        test_result("Results found", len(data.get("data", [])) > 0)
        if data.get("data"):
            for profile in data["data"]:
                test_result("country_id=US", profile.get("country_id") == "US")
                break


def test_search_male() -> None:
    """Test GET /api/profiles/search with 'male' query."""
    print("\n[GET /api/profiles/search - Male]")
    url = urljoin(BASE_URL, "/api/profiles/search?q=male&limit=5")
    r = requests.get(url, headers=HEADERS, timeout=10)
    
    assert_status_code(r, 200, "HTTP 200 status")
    data = assert_json_valid(r.text, "Valid JSON response")
    if data:
        assert_response_structure(data, ["status", "page", "limit", "total", "data"], "Response structure")
        test_result("Results found", len(data.get("data", [])) > 0)
        if data.get("data"):
            test_result("gender=male", data["data"][0].get("gender") == "male")


def test_search_young_males_from_nigeria() -> None:
    """Test natural language query for young males from Nigeria."""
    print("\n[GET /api/profiles/search - Young males from Nigeria]")
    url = urljoin(BASE_URL, "/api/profiles/search?q=young%20males%20from%20nigeria&limit=5")
    r = requests.get(url, headers=HEADERS, timeout=10)
    
    assert_status_code(r, 200, "HTTP 200 status")
    data = assert_json_valid(r.text, "Valid JSON response")
    if data:
        test_result("Results found", len(data.get("data", [])) > 0)
        if data.get("data"):
            profile = data["data"][0]
            test_result("gender=male", profile.get("gender") == "male")
            test_result("age 16-24", 16 <= profile.get("age", 0) <= 24)
            test_result("country_id=NG", profile.get("country_id") == "NG")


def test_search_females_above_30() -> None:
    """Test natural language query for females above 30."""
    print("\n[GET /api/profiles/search - Females above 30]")
    url = urljoin(BASE_URL, "/api/profiles/search?q=females%20above%2030&limit=5")
    r = requests.get(url, headers=HEADERS, timeout=10)
    
    assert_status_code(r, 200, "HTTP 200 status")
    data = assert_json_valid(r.text, "Valid JSON response")
    if data:
        test_result("Results found", len(data.get("data", [])) > 0)
        if data.get("data"):
            profile = data["data"][0]
            test_result("gender=female", profile.get("gender") == "female")
            test_result("age >= 30", profile.get("age", 0) >= 30)


def test_search_adult_males_from_kenya() -> None:
    """Test natural language query for adults from Kenya."""
    print("\n[GET /api/profiles/search - Adults from Kenya]")
    url = urljoin(BASE_URL, "/api/profiles/search?q=adults%20from%20kenya&limit=5")
    r = requests.get(url, headers=HEADERS, timeout=10)
    
    assert_status_code(r, 200, "HTTP 200 status")
    data = assert_json_valid(r.text, "Valid JSON response")
    if data:
        test_result("Results found", len(data.get("data", [])) > 0)
        if data.get("data"):
            profile = data["data"][0]
            test_result("age_group=adult", profile.get("age_group") == "adult")
            test_result("country_id=KE", profile.get("country_id") == "KE")


def test_search_unparseable() -> None:
    """Test natural language query that cannot be parsed."""
    print("\n[GET /api/profiles/search - Unparseable]")
    url = urljoin(BASE_URL, "/api/profiles/search?q=blorp%20qwerty")
    r = requests.get(url, headers=HEADERS, timeout=10)
    
    assert_status_code(r, 400, "HTTP 400 status")
    data = assert_json_valid(r.text, "Valid JSON response")
    if data:
        test_result("error status", data.get("status") == "error")
        test_result("Unable to interpret query message", "Unable to interpret query" in data.get("message", ""))


def test_search_empty_q() -> None:
    """Test natural language search with empty q parameter."""
    print("\n[GET /api/profiles/search - Empty q]")
    url = urljoin(BASE_URL, "/api/profiles/search?q=")
    r = requests.get(url, headers=HEADERS, timeout=10)
    
    assert_status_code(r, 400, "HTTP 400 status")
    data = assert_json_valid(r.text, "Valid JSON response")
    if data:
        test_result("q parameter required message", "q parameter is required" in data.get("message", ""))


def test_search_invalid_limit_type() -> None:
    """Test search with non-numeric limit."""
    print("\n[GET /api/profiles/search - Invalid limit type]")
    url = urljoin(BASE_URL, "/api/profiles/search?q=adult&limit=nope")
    r = requests.get(url, headers=HEADERS, timeout=10)
    
    assert_status_code(r, 422, "HTTP 422 status")
    data = assert_json_valid(r.text, "Valid JSON response")
    if data:
        test_result("Invalid parameter type message", "Invalid parameter type" in data.get("message", ""))


def test_search_unknown_param() -> None:
    """Test search with unknown parameter."""
    print("\n[GET /api/profiles/search - Unknown parameter]")
    url = urljoin(BASE_URL, "/api/profiles/search?q=adults&foo=bar")
    r = requests.get(url, headers=HEADERS, timeout=10)
    
    assert_status_code(r, 400, "HTTP 400 status")
    data = assert_json_valid(r.text, "Valid JSON response")
    if data:
        test_result("error status", data.get("status") == "error")


def test_post_missing_name() -> None:
    """Test POST /api/profiles with missing name field."""
    print("\n[POST /api/profiles - Missing name]")
    url = urljoin(BASE_URL, "/api/profiles")
    r = requests.post(url, json={}, headers=HEADERS, timeout=10)
    
    assert_status_code(r, 400, "HTTP 400 status")
    data = assert_json_valid(r.text, "Valid JSON response")
    if data:
        test_result("error status", data.get("status") == "error")
        test_result("name field required message", "name field is required" in data.get("message", ""))


def test_post_empty_name() -> None:
    """Test POST /api/profiles with empty name."""
    print("\n[POST /api/profiles - Empty name]")
    url = urljoin(BASE_URL, "/api/profiles")
    r = requests.post(url, json={"name": ""}, headers=HEADERS, timeout=10)
    
    assert_status_code(r, 400, "HTTP 400 status")
    data = assert_json_valid(r.text, "Valid JSON response")
    if data:
        test_result("error status", data.get("status") == "error")


def test_post_invalid_name_type() -> None:
    """Test POST /api/profiles with name as array."""
    print("\n[POST /api/profiles - Invalid name type]")
    url = urljoin(BASE_URL, "/api/profiles")
    r = requests.post(url, json={"name": ["a"]}, headers=HEADERS, timeout=10)
    
    assert_status_code(r, 422, "HTTP 422 status")
    data = assert_json_valid(r.text, "Valid JSON response")
    if data:
        test_result("error status", data.get("status") == "error")
        test_result("name must be string message", "name field must be a string" in data.get("message", ""))


def test_get_profiles_by_id_notfound() -> None:
    """Test GET /api/profiles/{id} with non-existent ID."""
    print("\n[GET /api/profiles/{id} - Not found]")
    url = urljoin(BASE_URL, "/api/profiles/00000000-0000-7000-8000-000000000000")
    r = requests.get(url, headers=HEADERS, timeout=10)
    
    assert_status_code(r, 404, "HTTP 404 status")
    data = assert_json_valid(r.text, "Valid JSON response")
    if data:
        test_result("error status", data.get("status") == "error")
        test_result("Profile not found message", "Profile not found" in data.get("message", ""))


def test_delete_by_id_notfound() -> None:
    """Test DELETE /api/profiles/{id} with non-existent ID."""
    print("\n[DELETE /api/profiles/{id} - Not found]")
    url = urljoin(BASE_URL, "/api/profiles/00000000-0000-7000-8000-000000000000")
    r = requests.delete(url, headers=HEADERS, timeout=10)
    
    assert_status_code(r, 404, "HTTP 404 status")
    data = assert_json_valid(r.text, "Valid JSON response")
    if data:
        test_result("error status", data.get("status") == "error")


def test_cors_headers() -> None:
    """Test CORS headers are present."""
    print("\n[CORS Headers]")
    url = urljoin(BASE_URL, "/api/profiles")
    r = requests.get(url, headers=HEADERS, timeout=10)
    
    test_result(
        "Access-Control-Allow-Origin header",
        "Access-Control-Allow-Origin" in r.headers,
    )
    if "Access-Control-Allow-Origin" in r.headers:
        test_result(
            "CORS allows *",
            r.headers["Access-Control-Allow-Origin"] == "*",
        )


def test_response_json_clean() -> None:
    """Test responses start with valid JSON (no warnings)."""
    print("\n[Response Cleanliness]")
    url = urljoin(BASE_URL, "/api/profiles?page=1&limit=1")
    r = requests.get(url, headers=HEADERS, timeout=10)
    
    # Response should start with { and be valid JSON
    text = r.text.strip()
    starts_with_brace = text.startswith("{")
    test_result("Response starts with {", starts_with_brace)
    
    data = assert_json_valid(r.text, "Valid JSON with no warnings")


def test_timestamp_format() -> None:
    """Test created_at timestamps are in UTC ISO 8601 format."""
    print("\n[Timestamp Format]")
    url = urljoin(BASE_URL, "/api/profiles?page=1&limit=1")
    r = requests.get(url, headers=HEADERS, timeout=10)
    
    data = assert_json_valid(r.text, "Valid JSON response")
    if data and data.get("data"):
        profile = data["data"][0]
        timestamp = profile.get("created_at", "")
        # Check for ISO 8601 format with Z suffix
        test_result(
            "created_at in ISO 8601 format with Z",
            "T" in timestamp and timestamp.endswith("Z"),
        )


def test_uuid_format() -> None:
    """Test profile IDs are UUID v7."""
    print("\n[UUID Format]")
    url = urljoin(BASE_URL, "/api/profiles?page=1&limit=1")
    r = requests.get(url, headers=HEADERS, timeout=10)
    
    data = assert_json_valid(r.text, "Valid JSON response")
    if data and data.get("data"):
        profile = data["data"][0]
        uuid = profile.get("id", "")
        # UUID format: 8-4-4-4-12 hex characters
        parts = uuid.split("-")
        test_result(
            "ID is UUID format",
            len(parts) == 5 and all(len(p) in [8, 4, 4, 4, 12] for p in parts),
        )
        # Version nibble should be 7 for UUID v7
        if len(parts[2]) == 4:
            test_result("ID is UUID v7", parts[2][0] == "7")


def test_sample_size_not_in_response() -> None:
    """Test that sample_size field is NOT in response (Stage 2 requirement)."""
    print("\n[Schema Compliance]")
    url = urljoin(BASE_URL, "/api/profiles?page=1&limit=1")
    r = requests.get(url, headers=HEADERS, timeout=10)
    
    data = assert_json_valid(r.text, "Valid JSON response")
    if data and data.get("data"):
        profile = data["data"][0]
        test_result("sample_size NOT in response", "sample_size" not in profile)
        test_result("country_name included", "country_name" in profile)


# ============================================================================
# MAIN
# ============================================================================


def main() -> None:
    """Run all tests."""
    print("=" * 80)
    print("PROFILES API STAGE 2 ENDPOINT TEST SUITE - GCP DEPLOYMENT")
    print(f"Base URL: {BASE_URL}")
    print("=" * 80)

    # GET /api/profiles tests
    test_get_profiles_default()
    test_get_profiles_combined_filters()
    test_get_profiles_sorting_desc()
    test_get_profiles_invalid_sort()
    test_get_profiles_invalid_order()
    test_get_profiles_invalid_numeric_type()
    test_get_profiles_unknown_param()
    test_get_profiles_pagination_limit_cap()
    test_get_profiles_by_age_group()
    test_get_profiles_by_country()

    # GET /api/profiles/search tests
    test_search_male()
    test_search_young_males_from_nigeria()
    test_search_females_above_30()
    test_search_adult_males_from_kenya()
    test_search_unparseable()
    test_search_empty_q()
    test_search_invalid_limit_type()
    test_search_unknown_param()

    # POST /api/profiles tests (validation only)
    test_post_missing_name()
    test_post_empty_name()
    test_post_invalid_name_type()

    # GET /api/profiles/{id} tests
    test_get_profiles_by_id_notfound()

    # DELETE /api/profiles/{id} tests
    test_delete_by_id_notfound()

    # Header and format tests
    test_cors_headers()
    test_response_json_clean()
    test_timestamp_format()
    test_uuid_format()
    test_sample_size_not_in_response()

    # Summary
    print("\n" + "=" * 80)
    print(f"RESULTS: {passed} passed, {failed} failed")
    print("=" * 80)

    if errors:
        print("\nFailed Tests:")
        for error in errors:
            print(f"  - {error}")

    sys.exit(0 if failed == 0 else 1)


if __name__ == "__main__":
    main()
