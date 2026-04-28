#!/usr/bin/env python3
"""
Diagnostic test script to inspect actual API responses.
"""

import requests
import json
from urllib.parse import urljoin

BASE_URL = "https://profiles-api.duckdns.org"
HEADERS = {"Content-Type": "application/json", "Accept": "application/json"}


def test_endpoint(method: str, path: str, data: dict = None) -> None:
    """Test a single endpoint and display response."""
    url = urljoin(BASE_URL, path)
    print(f"\n{method} {path}")
    print(f"URL: {url}")
    
    try:
        if method == "GET":
            r = requests.get(url, headers=HEADERS, timeout=10)
        elif method == "POST":
            r = requests.post(url, json=data, headers=HEADERS, timeout=10)
        elif method == "DELETE":
            r = requests.delete(url, headers=HEADERS, timeout=10)
        else:
            print(f"Unknown method: {method}")
            return
        
        print(f"Status Code: {r.status_code}")
        print(f"Headers: {dict(r.headers)}")
        print(f"Response Body:")
        print(r.text[:2000])  # First 2000 chars
        
    except Exception as e:
        print(f"ERROR: {type(e).__name__}: {str(e)}")


# Run diagnostics
print("=" * 80)
print("PROFILES API DIAGNOSTIC TEST")
print(f"Base URL: {BASE_URL}")
print("=" * 80)

test_endpoint("GET", "/api/profiles?page=1&limit=1")
test_endpoint("GET", "/api/profiles/search?q=male")
test_endpoint("POST", "/api/profiles", {"name": "test"})
test_endpoint("GET", "/api/profiles/00000000-0000-7000-8000-000000000000")

print("\n" + "=" * 80)
