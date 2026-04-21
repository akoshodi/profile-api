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
        $dbPath = $_ENV["DB_PATH"] ?? "database/profiles.db";

        // Resolve relative DB paths from project root so CLI scripts and web runtime
        // use the same SQLite file regardless of current working directory.
        if (!preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $dbPath)) {
            $dbPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . $dbPath;
        }

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
                 age                 INTEGER,
                 age_group           TEXT,
                 country_id          TEXT,
                 country_name        TEXT,
                 country_probability REAL,
                 created_at          TEXT NOT NULL
             )
         ");

         // Add country_name column if it doesn't exist yet
         // (for existing databases being upgraded from Stage 1)
         try {
             $this->pdo->exec("ALTER TABLE profiles ADD COLUMN country_name TEXT");
         } catch (\PDOException $e) {
             // Column already exists — safe to ignore
         }

         // Indexes for performance — no full table scans
         $this->pdo->exec("
             CREATE INDEX IF NOT EXISTS idx_gender     ON profiles(gender);
             CREATE INDEX IF NOT EXISTS idx_age_group  ON profiles(age_group);
             CREATE INDEX IF NOT EXISTS idx_country_id ON profiles(country_id);
             CREATE INDEX IF NOT EXISTS idx_age        ON profiles(age);
                 CREATE INDEX IF NOT EXISTS idx_gender_probability  ON profiles(gender_probability);
                 CREATE INDEX IF NOT EXISTS idx_country_probability ON profiles(country_probability);
             CREATE INDEX IF NOT EXISTS idx_created_at ON profiles(created_at);
         ");
     }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }
}
