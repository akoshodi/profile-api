<?php

namespace App;

use PDO;
use PDOException;
use Ramsey\Uuid\Uuid;
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

         $this->pdo->exec("
             CREATE TABLE IF NOT EXISTS users (
                 id            TEXT PRIMARY KEY,
                 github_id     TEXT UNIQUE,
                 username      TEXT,
                 email         TEXT UNIQUE,
                 avatar_url    TEXT,
                 role          TEXT NOT NULL DEFAULT 'analyst',
                 is_active     INTEGER NOT NULL DEFAULT 1,
                 last_login_at TEXT,
                 created_at    TEXT NOT NULL
             )
         ");

         $this->pdo->exec("
             CREATE TABLE IF NOT EXISTS access_tokens (
                 id         TEXT PRIMARY KEY,
                 user_id    TEXT NOT NULL,
                 token_hash TEXT NOT NULL UNIQUE,
                 expires_at TEXT NOT NULL,
                 revoked_at TEXT,
                 created_at TEXT NOT NULL,
                 FOREIGN KEY(user_id) REFERENCES users(id)
             )
         ");

         $this->pdo->exec("
             CREATE TABLE IF NOT EXISTS refresh_tokens (
                 id               TEXT PRIMARY KEY,
                 user_id          TEXT NOT NULL,
                 token_hash       TEXT NOT NULL UNIQUE,
                 expires_at       TEXT NOT NULL,
                 revoked_at       TEXT,
                 replaced_by_hash TEXT,
                 created_at       TEXT NOT NULL,
                 FOREIGN KEY(user_id) REFERENCES users(id)
             )
         ");

         $this->pdo->exec("
             CREATE TABLE IF NOT EXISTS oauth_states (
                 state         TEXT PRIMARY KEY,
                 code_challenge TEXT,
                 mode          TEXT NOT NULL,
                 redirect_uri  TEXT,
                 code_verifier TEXT,
                 expires_at    TEXT NOT NULL,
                 used_at       TEXT,
                 created_at    TEXT NOT NULL
             )
         ");

         $this->pdo->exec("
             CREATE TABLE IF NOT EXISTS rate_limits (
                 key          TEXT PRIMARY KEY,
                 counter      INTEGER NOT NULL,
                 window_start INTEGER NOT NULL
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
             CREATE INDEX IF NOT EXISTS idx_users_role ON users(role);
             CREATE INDEX IF NOT EXISTS idx_access_user ON access_tokens(user_id);
             CREATE INDEX IF NOT EXISTS idx_access_expiry ON access_tokens(expires_at);
             CREATE INDEX IF NOT EXISTS idx_refresh_user ON refresh_tokens(user_id);
             CREATE INDEX IF NOT EXISTS idx_refresh_expiry ON refresh_tokens(expires_at);
         ");

         $this->seedAdminUser();
     }

    private function seedAdminUser(): void
    {
        $adminEmail = strtolower(trim($_ENV["ADMIN_EMAIL"] ?? "akoshodi@gmail.com"));
        if ($adminEmail === "") {
            return;
        }

        $username = explode("@", $adminEmail)[0] ?: "admin";
        $now = gmdate("Y-m-d\\TH:i:s\\Z");

        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?)");
        $stmt->execute([$adminEmail]);
        $existing = $stmt->fetch();

        if ($existing) {
            $update = $this->pdo->prepare(
                "UPDATE users SET role = 'admin', is_active = 1 WHERE id = ?",
            );
            $update->execute([$existing["id"]]);
            return;
        }

        $insert = $this->pdo->prepare(
            "INSERT INTO users (id, github_id, username, email, avatar_url, role, is_active, last_login_at, created_at)
             VALUES (:id, :github_id, :username, :email, :avatar_url, :role, :is_active, :last_login_at, :created_at)",
        );

        $insert->execute([
            ":id" => Uuid::uuid7()->toString(),
            ":github_id" => "seed-admin",
            ":username" => $username,
            ":email" => $adminEmail,
            ":avatar_url" => null,
            ":role" => "admin",
            ":is_active" => 1,
            ":last_login_at" => null,
            ":created_at" => $now,
        ]);
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }
}
