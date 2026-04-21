<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use RuntimeException;

class NationalizeService
{
    private Client $client;

    // ISO 3166-1 alpha-2 country code → full name map
    private const COUNTRY_NAMES = [
        "AF" => "Afghanistan",
        "AL" => "Albania",
        "DZ" => "Algeria",
        "AO" => "Angola",
        "AR" => "Argentina",
        "AU" => "Australia",
        "AT" => "Austria",
        "BE" => "Belgium",
        "BJ" => "Benin",
        "BR" => "Brazil",
        "BF" => "Burkina Faso",
        "BI" => "Burundi",
        "CM" => "Cameroon",
        "CA" => "Canada",
        "CF" => "Central African Republic",
        "TD" => "Chad",
        "CL" => "Chile",
        "CN" => "China",
        "CO" => "Colombia",
        "CD" => "DR Congo",
        "CG" => "Congo",
        "CI" => "Ivory Coast",
        "HR" => "Croatia",
        "CZ" => "Czech Republic",
        "DK" => "Denmark",
        "DJ" => "Djibouti",
        "EG" => "Egypt",
        "ET" => "Ethiopia",
        "FI" => "Finland",
        "FR" => "France",
        "GA" => "Gabon",
        "GM" => "Gambia",
        "DE" => "Germany",
        "GH" => "Ghana",
        "GR" => "Greece",
        "GT" => "Guatemala",
        "GN" => "Guinea",
        "HU" => "Hungary",
        "IN" => "India",
        "ID" => "Indonesia",
        "IR" => "Iran",
        "IQ" => "Iraq",
        "IE" => "Ireland",
        "IL" => "Israel",
        "IT" => "Italy",
        "JP" => "Japan",
        "JO" => "Jordan",
        "KZ" => "Kazakhstan",
        "KE" => "Kenya",
        "KW" => "Kuwait",
        "LB" => "Lebanon",
        "LY" => "Libya",
        "MG" => "Madagascar",
        "MW" => "Malawi",
        "MY" => "Malaysia",
        "ML" => "Mali",
        "MR" => "Mauritania",
        "MX" => "Mexico",
        "MA" => "Morocco",
        "MZ" => "Mozambique",
        "NA" => "Namibia",
        "NL" => "Netherlands",
        "NZ" => "New Zealand",
        "NE" => "Niger",
        "NG" => "Nigeria",
        "NO" => "Norway",
        "PK" => "Pakistan",
        "PE" => "Peru",
        "PH" => "Philippines",
        "PL" => "Poland",
        "PT" => "Portugal",
        "RO" => "Romania",
        "RU" => "Russia",
        "RW" => "Rwanda",
        "SA" => "Saudi Arabia",
        "SN" => "Senegal",
        "SL" => "Sierra Leone",
        "SO" => "Somalia",
        "ZA" => "South Africa",
        "SS" => "South Sudan",
        "ES" => "Spain",
        "SD" => "Sudan",
        "SE" => "Sweden",
        "CH" => "Switzerland",
        "SY" => "Syria",
        "TZ" => "Tanzania",
        "TH" => "Thailand",
        "TG" => "Togo",
        "TN" => "Tunisia",
        "TR" => "Turkey",
        "UG" => "Uganda",
        "UA" => "Ukraine",
        "AE" => "United Arab Emirates",
        "GB" => "United Kingdom",
        "US" => "United States",
        "UY" => "Uruguay",
        "VE" => "Venezuela",
        "VN" => "Vietnam",
        "YE" => "Yemen",
        "ZM" => "Zambia",
        "ZW" => "Zimbabwe",
    ];

    public function __construct()
    {
        $this->client = new Client([
            "base_uri" =>
                $_ENV["NATIONALIZE_URL"] ?? "https://api.nationalize.io",
            "timeout" => 5.0,
        ]);
    }

    public function classify(string $name): array
    {
        try {
            $response = $this->client->get("/", ["query" => ["name" => $name]]);
            $body = json_decode(
                (string) $response->getBody(),
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            );

            if (empty($body["country"]) || !is_array($body["country"])) {
                throw new RuntimeException(
                    "Nationalize returned an invalid response",
                    502,
                );
            }

            $top = collect_top($body["country"]);

            return [
                "country_id" => $top["country_id"],
                "country_name" =>
                    self::COUNTRY_NAMES[$top["country_id"]] ??
                    $top["country_id"],
                "country_probability" => (float) $top["probability"],
            ];
        } catch (RuntimeException $e) {
            throw $e;
        } catch (ServerException | ConnectException $e) {
            throw new RuntimeException(
                "Nationalize returned an invalid response",
                502,
            );
        }
    }
}

function collect_top(array $countries): array
{
    return array_reduce(
        $countries,
        fn($carry, $item) => $carry === null ||
        $item["probability"] > $carry["probability"]
            ? $item
            : $carry,
        null,
    );
}
