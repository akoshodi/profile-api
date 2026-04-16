<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use RuntimeException;

class AgifyService
{
    private Client $client;

    public function __construct()
    {
        $this->client = new Client([
            "base_uri" => $_ENV["AGIFY_URL"] ?? "https://api.agify.io",
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

            // Edge case: null age
            if (empty($body["age"])) {
                throw new RuntimeException(
                    "Agify returned an invalid response",
                    502,
                );
            }

            $age = (int) $body["age"];

            return [
                "age" => $age,
                "age_group" => $this->resolveAgeGroup($age),
            ];
        } catch (RuntimeException $e) {
            throw $e;
        } catch (ServerException | ConnectException $e) {
            throw new RuntimeException(
                "Agify returned an invalid response",
                502,
            );
        }
    }

    /**
     * Classification rules:
     * 0–12   → child
     * 13–19  → teenager
     * 20–59  → adult
     * 60+    → senior
     */
    private function resolveAgeGroup(int $age): string
    {
        return match (true) {
            $age <= 12 => "child",
            $age <= 19 => "teenager",
            $age <= 59 => "adult",
            default => "senior",
        };
    }
}
