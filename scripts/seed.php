<?php

declare(strict_types=1);

require __DIR__ . "/../vendor/autoload.php";

use Dotenv\Dotenv;
use Ramsey\Uuid\Uuid;

// Load env
$dotenv = Dotenv::createImmutable(__DIR__ . "/..");
$dotenv->safeLoad();

// Boot Database class — this runs the migration and creates the profiles table
$db = new \App\Database();
$pdo = $db->getPdo();

// Load seed data
$jsonPath = __DIR__ . "/../data/profiles.json";
if (!file_exists($jsonPath)) {
    echo "Error: data/profiles.json not found.\n";
    exit(1);
}

$decoded = json_decode(file_get_contents($jsonPath), associative: true);
if (!$decoded) {
    echo "Error: Could not parse profiles.json\n";
    exit(1);
}

// JSON is wrapped in a "profiles" key
$profiles = $decoded["profiles"] ?? $decoded;

if (empty($profiles) || !is_array($profiles)) {
    echo "Error: Could not find profiles array in JSON\n";
    exit(1);
}

$inserted = 0;
$skipped = 0;

$stmt = $pdo->prepare("
    INSERT OR IGNORE INTO profiles
        (id, name, gender, gender_probability, age, age_group,
         country_id, country_name, country_probability, created_at)
    VALUES
        (:id, :name, :gender, :gender_probability, :age, :age_group,
         :country_id, :country_name, :country_probability, :created_at)
");

// Country name lookup (same map as NationalizeService)
$countryNames = [
    "NG" => "Nigeria",
    "GH" => "Ghana",
    "KE" => "Kenya",
    "ET" => "Ethiopia",
    "TZ" => "Tanzania",
    "UG" => "Uganda",
    "ZA" => "South Africa",
    "EG" => "Egypt",
    "DZ" => "Algeria",
    "MA" => "Morocco",
    "AO" => "Angola",
    "CM" => "Cameroon",
    "CI" => "Ivory Coast",
    "SN" => "Senegal",
    "ML" => "Mali",
    "NE" => "Niger",
    "BF" => "Burkina Faso",
    "MZ" => "Mozambique",
    "MG" => "Madagascar",
    "ZM" => "Zambia",
    "ZW" => "Zimbabwe",
    "SO" => "Somalia",
    "SD" => "Sudan",
    "SS" => "South Sudan",
    "RW" => "Rwanda",
    "BJ" => "Benin",
    "TG" => "Togo",
    "SL" => "Sierra Leone",
    "LY" => "Libya",
    "CG" => "Congo",
    "CD" => "DR Congo",
    "GA" => "Gabon",
    "US" => "United States",
    "GB" => "United Kingdom",
    "FR" => "France",
    "DE" => "Germany",
    "IN" => "India",
    "BR" => "Brazil",
    "CN" => "China",
    "JP" => "Japan",
    "RU" => "Russia",
    "TR" => "Turkey",
    "PK" => "Pakistan",
    "ID" => "Indonesia",
    "NG" => "Nigeria",
];

$ageGroup = function (int $age): string {
    return match (true) {
        $age <= 12 => "child",
        $age <= 19 => "teenager",
        $age <= 59 => "adult",
        default => "senior",
    };
};

foreach ($profiles as $profile) {
    $countryId = $profile["country_id"] ?? null;
    $age = isset($profile["age"]) ? (int) $profile["age"] : null;

    $result = $stmt->execute([
        ":id" => Uuid::uuid7()->toString(),
        ":name" => strtolower(trim($profile["name"])),
        ":gender" => $profile["gender"] ?? null,
        ":gender_probability" => isset($profile["gender_probability"])
            ? (float) $profile["gender_probability"]
            : null,
        ":age" => $age,
        ":age_group" =>
            $age !== null ? $ageGroup($age) : $profile["age_group"] ?? null,
        ":country_id" => $countryId,
        ":country_name" =>
            $profile["country_name"] ??
            ($countryNames[$countryId] ?? $countryId),
        ":country_probability" => isset($profile["country_probability"])
            ? (float) $profile["country_probability"]
            : null,
        ":created_at" =>
            $profile["created_at"] ??
            new \DateTimeImmutable("now", new \DateTimeZone("UTC"))->format(
                "Y-m-d\TH:i:s\Z",
            ),
    ]);

    if ($stmt->rowCount() > 0) {
        $inserted++;
    } else {
        $skipped++;
    }
}

echo "Seed complete: {$inserted} inserted, {$skipped} skipped (duplicates).\n";
