<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use RuntimeException;

class NationalizeService
{
    private Client $client;

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

            // Edge case: empty or missing country array
            if (empty($body["country"]) || !is_array($body["country"])) {
                throw new RuntimeException(
                    "Nationalize returned an invalid response",
                    502,
                );
            }

            // Pick the country with the highest probability
            $top = collect_top($body["country"]);

            return [
                "country_id" => $top["country_id"],
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

/**
 * Pure function — find the array entry with the highest probability.
 * Kept outside the class for simplicity and testability.
 */
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
