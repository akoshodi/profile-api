<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use RuntimeException;

class GenderizeService
{
    private Client $client;

    public function __construct()
    {
        $this->client = new Client([
            "base_uri" => $_ENV["GENDERIZE_URL"] ?? "https://api.genderize.io",
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

            // Edge case: null gender or zero count
            if (empty($body["gender"]) || empty($body["count"])) {
                throw new RuntimeException(
                    "Genderize returned an invalid response",
                    502,
                );
            }

            return [
                "gender" => $body["gender"],
                "gender_probability" => (float) $body["probability"],
                "sample_size" => (int) $body["count"],
            ];
        } catch (RuntimeException $e) {
            throw $e; // re-throw our own exceptions untouched
        } catch (ServerException | ConnectException $e) {
            throw new RuntimeException(
                "Genderize returned an invalid response",
                502,
            );
        }
    }
}
