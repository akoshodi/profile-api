<?php

namespace App;

use PDO;
use PDOException;
use RuntimeException;

class Database
{
    private PDO $pdo;

    public function __construct()
    {
        $dbPath = $_ENV["DB_PATH"] ?? "/app/database/profiles.db";
        $dir = dirname($dbPath);

        // Create directory if it doesn't exist
        if (!is_dir($dir)) {
            mkdir($dir, 0755, recursive: true);
        }

        try {
            $this->pdo = new PDO("sqlite:{$dbPath}");
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(
                PDO::ATTR_DEFAULT_FETCH_MODE,
                PDO::FETCH_ASSOC,
            );

            // Enable WAL mode for better concurrent read performance
            $this->pdo->exec("PRAGMA journal_mode=WAL");

            $this->migrate();
        } catch (PDOException $e) {
            throw new RuntimeException(
                "Database connection failed: " . $e->getMessage(),
            );
        }
    }

    /**
     * Run table creation if it doesn't exist yet.
     * Idempotent — safe to call on every boot.
     */
    private function migrate(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS profiles (
                id                  TEXT PRIMARY KEY,
                name                TEXT NOT NULL UNIQUE,
                gender              TEXT,
                gender_probability  REAL,
                sample_size         INTEGER,
                age                 INTEGER,
                age_group           TEXT,
                country_id          TEXT,
                country_probability REAL,
                created_at          TEXT NOT NULL
            )
        ");
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }
}
