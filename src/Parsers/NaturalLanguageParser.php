<?php

namespace App\Parsers;

class NaturalLanguageParser
{
    // Country name → ISO code. Lowercase keys for case-insensitive matching.
    private const COUNTRY_MAP = [
        "nigeria" => "NG",
        "ghana" => "GH",
        "kenya" => "KE",
        "ethiopia" => "ET",
        "tanzania" => "TZ",
        "uganda" => "UG",
        "south africa" => "ZA",
        "egypt" => "EG",
        "algeria" => "DZ",
        "morocco" => "MA",
        "angola" => "AO",
        "cameroon" => "CM",
        "ivory coast" => "CI",
        "senegal" => "SN",
        "mali" => "ML",
        "niger" => "NE",
        "burkina faso" => "BF",
        "mozambique" => "MZ",
        "madagascar" => "MG",
        "zambia" => "ZM",
        "zimbabwe" => "ZW",
        "somalia" => "SO",
        "sudan" => "SD",
        "south sudan" => "SS",
        "rwanda" => "RW",
        "benin" => "BJ",
        "togo" => "TG",
        "sierra leone" => "SL",
        "libya" => "LY",
        "congo" => "CG",
        "dr congo" => "CD",
        "gabon" => "GA",
        "united states" => "US",
        "usa" => "US",
        "united kingdom" => "GB",
        "uk" => "GB",
        "france" => "FR",
        "germany" => "DE",
        "italy" => "IT",
        "spain" => "ES",
        "portugal" => "PT",
        "netherlands" => "NL",
        "belgium" => "BE",
        "sweden" => "SE",
        "norway" => "NO",
        "denmark" => "DK",
        "finland" => "FI",
        "poland" => "PL",
        "russia" => "RU",
        "ukraine" => "UA",
        "china" => "CN",
        "japan" => "JP",
        "india" => "IN",
        "pakistan" => "PK",
        "indonesia" => "ID",
        "philippines" => "PH",
        "vietnam" => "VN",
        "thailand" => "TH",
        "malaysia" => "MY",
        "saudi arabia" => "SA",
        "turkey" => "TR",
        "iran" => "IR",
        "iraq" => "IQ",
        "jordan" => "JO",
        "brazil" => "BR",
        "mexico" => "MX",
        "colombia" => "CO",
        "argentina" => "AR",
        "peru" => "PE",
        "venezuela" => "VE",
        "australia" => "AU",
        "new zealand" => "NZ",
        "canada" => "CA",
    ];

    /**
     * Parse a natural language query into filter parameters.
     * Returns an array of filters or throws if unparseable.
     *
     * @throws \RuntimeException with message "Unable to interpret query"
     */
    public function parse(string $query): array
{
    $normalized = preg_replace('/\s+/', ' ', trim($query));
    $q          = strtolower($normalized ?? trim($query));
    $filters = [];
    $matched = false;

    // ── Gender ────────────────────────────────────────────────────────────────
    $hasMale   = preg_match('/\b(males?|men|man)\b/', $q);
    $hasFemale = preg_match('/\b(females?|women|woman|girls?)\b/', $q);

    if ($hasMale && !$hasFemale) {
        $filters['gender'] = 'male';
        $matched = true;
    } elseif ($hasFemale && !$hasMale) {
        $filters['gender'] = 'female';
        $matched = true;
    } elseif ($hasMale && $hasFemale) {
        // both mentioned — no gender filter but still parseable
        $matched = true;
    }

    // ── Age group ─────────────────────────────────────────────────────────────
    if (preg_match('/\b(children|child|kids?)\b/', $q)) {
        $filters['age_group'] = 'child';
        $matched = true;
    } elseif (preg_match('/\b(teenagers?|teens?|adolescents?)\b/', $q)) {
        $filters['age_group'] = 'teenager';
        $matched = true;
    } elseif (preg_match('/\badults?\b/', $q)) {
        $filters['age_group'] = 'adult';
        $matched = true;
    } elseif (preg_match('/\b(seniors?|elderly|old people)\b/', $q)) {
        $filters['age_group'] = 'senior';
        $matched = true;
    }

    // ── "young" → ages 16–24 ─────────────────────────────────────────────────
    if (preg_match('/\byoung\b/', $q)) {
        $filters['min_age'] = 16;
        $filters['max_age'] = 24;
        $matched = true;
    }

    // ── Explicit age comparisons ──────────────────────────────────────────────
    // "above X" / "over X" / "older than X" / "greater than X"
    if (preg_match('/\b(?:above|over|older\s+than|greater\s+than)\s+(\d+)\b/', $q, $m)) {
        $filters['min_age'] = (int) $m[1];
        $matched = true;
    }

    // "below X" / "under X" / "younger than X" / "less than X"
    if (preg_match('/\b(?:below|under|younger\s+than|less\s+than)\s+(\d+)\b/', $q, $m)) {
        $filters['max_age'] = (int) $m[1];
        $matched = true;
    }

    // "between X and Y"
    if (preg_match('/\bbetween\s+(\d+)\s+and\s+(\d+)\b/', $q, $m)) {
        $filters['min_age'] = (int) $m[1];
        $filters['max_age'] = (int) $m[2];
        $matched = true;
    }

    // "aged X" / "age X"
    if (preg_match('/\baged?\s+(\d+)\b/', $q, $m)) {
        $filters['min_age'] = (int) $m[1];
        $filters['max_age'] = (int) $m[1];
        $matched = true;
    }

    // ── Country ───────────────────────────────────────────────────────────────
    // Sort by length descending so "south africa" matches before "africa"
    $sortedCountries = array_keys(self::COUNTRY_MAP);
    usort($sortedCountries, fn($a, $b) => strlen($b) - strlen($a));

    foreach ($sortedCountries as $countryName) {
        if (str_contains($q, $countryName)) {
            $filters['country_id'] = self::COUNTRY_MAP[$countryName];
            $matched = true;
            break;
        }
    }

    // Bare ISO code: "from NG" / "in KE"
    if (!isset($filters['country_id'])) {
        if (preg_match('/\b(?:from|in)\s+([A-Za-z]{2})\b/', $q, $m)) {
            $candidate = strtoupper($m[1]);
            // Only accept it if it's a known ISO code in our map
            if (in_array($candidate, self::COUNTRY_MAP, strict: true)) {
                $filters['country_id'] = $candidate;
                $matched = true;
            }
        }
    }

    // ── Generic parseable terms ───────────────────────────────────────────────
    if (preg_match('/\b(people|persons?|profiles?|everyone|all)\b/', $q)) {
        $matched = true;
    }

    if (!$matched) {
        throw new \RuntimeException('Unable to interpret query');
    }

    return $filters;
}
}
