<?php

declare(strict_types=1);

require __DIR__ . "/../vendor/autoload.php";

use App\Database;
use App\Parsers\NaturalLanguageParser;
use App\Services\AgifyService;
use App\Services\GenderizeService;
use App\Services\NationalizeService;
use DI\ContainerBuilder;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ramsey\Uuid\Uuid;
use Slim\Factory\AppFactory;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . "/..");
$dotenv->safeLoad();

$builder = new ContainerBuilder();
$builder->addDefinitions([
    Database::class => \DI\create(Database::class),
    GenderizeService::class => \DI\create(GenderizeService::class),
    AgifyService::class => \DI\create(AgifyService::class),
    NationalizeService::class => \DI\create(NationalizeService::class),
]);
$container = $builder->build();

AppFactory::setContainer($container);
$app = AppFactory::create();
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();

$errorMiddleware = $app->addErrorMiddleware(false, true, true);
$errorMiddleware->setDefaultErrorHandler(function (
    Request $request,
    Throwable $exception,
    bool $displayErrorDetails,
) use ($app): Response {
    $response = $app->getResponseFactory()->createResponse();

    $status = 500;
    $message = "Internal server error.";

    if ($exception instanceof Slim\Exception\HttpNotFoundException) {
        $status = 404;
        $message = "The requested route does not exist.";
    } elseif ($exception instanceof Slim\Exception\HttpMethodNotAllowedException) {
        $status = 405;
        $message = "Method not allowed.";
    }

    return errorJson($response, $message, $status);
});

function jsonResponse(Response $response, array $data, int $status = 200): Response
{
    $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($status);
}

function errorJson(Response $response, string $message, int $status): Response
{
    return jsonResponse($response, [
        "status" => "error",
        "message" => $message,
    ], $status);
}

function utcNow(): string
{
    return gmdate("Y-m-d\\TH:i:s\\Z");
}

function utcPlusMinutes(int $minutes): string
{
    return gmdate("Y-m-d\\TH:i:s\\Z", time() + ($minutes * 60));
}

function tokenHash(string $token): string
{
    return hash("sha256", $token);
}

function randomToken(int $bytes = 32): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), "+/", "-_"), "=");
}

function b64UrlEncode(string $input): string
{
    return rtrim(strtr(base64_encode($input), "+/", "-_"), "=");
}

function pkceChallenge(string $verifier): string
{
    return b64UrlEncode(hash("sha256", $verifier, true));
}

function fullProfile(array $row): array
{
    return [
        "id" => $row["id"],
        "name" => $row["name"],
        "gender" => $row["gender"],
        "gender_probability" => (float) $row["gender_probability"],
        "age" => (int) $row["age"],
        "age_group" => $row["age_group"],
        "country_id" => $row["country_id"],
        "country_name" => $row["country_name"] ?? null,
        "country_probability" => (float) $row["country_probability"],
        "created_at" => $row["created_at"],
    ];
}

function cookieSecure(): bool
{
    return ($_ENV["COOKIE_SECURE"] ?? "false") === "true";
}

function getBearerToken(Request $request): ?string
{
    $header = $request->getHeaderLine("Authorization");
    if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        return trim($m[1]);
    }
    return null;
}

function appendCookieHeader(
    Response $response,
    string $name,
    string $value,
    int $maxAgeSeconds,
    bool $httpOnly = true,
): Response {
    $parts = [
        sprintf("%s=%s", $name, rawurlencode($value)),
        "Path=/",
        "Max-Age=" . $maxAgeSeconds,
        "SameSite=Lax",
    ];

    if ($httpOnly) {
        $parts[] = "HttpOnly";
    }
    if (cookieSecure()) {
        $parts[] = "Secure";
    }

    return $response->withAddedHeader("Set-Cookie", implode("; ", $parts));
}

function appendExpiredCookieHeader(Response $response, string $name): Response
{
    $parts = [
        sprintf("%s=", $name),
        "Path=/",
        "Expires=Thu, 01 Jan 1970 00:00:00 GMT",
        "Max-Age=0",
        "SameSite=Lax",
    ];
    if (cookieSecure()) {
        $parts[] = "Secure";
    }
    $parts[] = "HttpOnly";

    return $response->withAddedHeader("Set-Cookie", implode("; ", $parts));
}

function buildPageLinks(string $path, array $query, int $page, int $limit, int $totalPages): array
{
    $base = $query;
    $base["limit"] = $limit;
    $base["page"] = $page;

    $self = $path . "?" . http_build_query($base);
    $next = null;
    $prev = null;

    if ($page < $totalPages) {
        $base["page"] = $page + 1;
        $next = $path . "?" . http_build_query($base);
    }
    if ($page > 1 && $totalPages > 0) {
        $base["page"] = $page - 1;
        $prev = $path . "?" . http_build_query($base);
    }

    return [
        "self" => $self,
        "next" => $next,
        "prev" => $prev,
    ];
}

function enforceRateLimit(PDO $pdo, string $key, int $limitPerMinute): bool
{
    $window = (int) floor(time() / 60);

    $stmt = $pdo->prepare("SELECT counter, window_start FROM rate_limits WHERE key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();

    if (!$row) {
        $insert = $pdo->prepare("INSERT INTO rate_limits (key, counter, window_start) VALUES (?, 1, ?)");
        $insert->execute([$key, $window]);
        return true;
    }

    $counter = (int) $row["counter"];
    $windowStart = (int) $row["window_start"];

    if ($windowStart !== $window) {
        $update = $pdo->prepare("UPDATE rate_limits SET counter = 1, window_start = ? WHERE key = ?");
        $update->execute([$window, $key]);
        return true;
    }

    if ($counter >= $limitPerMinute) {
        return false;
    }

    $update = $pdo->prepare("UPDATE rate_limits SET counter = counter + 1 WHERE key = ?");
    $update->execute([$key]);
    return true;
}

function authenticateUser(PDO $pdo, string $accessToken): ?array
{
    $stmt = $pdo->prepare(
        "SELECT u.*
         FROM access_tokens t
         JOIN users u ON u.id = t.user_id
         WHERE t.token_hash = :token_hash
           AND t.revoked_at IS NULL
           AND t.expires_at > :now
         LIMIT 1",
    );

    $stmt->execute([
        ":token_hash" => tokenHash($accessToken),
        ":now" => utcNow(),
    ]);

    $user = $stmt->fetch();
    return $user ?: null;
}

function issueTokenPair(PDO $pdo, string $userId): array
{
    $accessToken = randomToken(32);
    $refreshToken = randomToken(48);

    $accessExpires = utcPlusMinutes(3);
    $refreshExpires = utcPlusMinutes(5);
    $now = utcNow();

    $accessInsert = $pdo->prepare(
        "INSERT INTO access_tokens (id, user_id, token_hash, expires_at, revoked_at, created_at)
         VALUES (:id, :user_id, :token_hash, :expires_at, NULL, :created_at)",
    );
    $accessInsert->execute([
        ":id" => Uuid::uuid7()->toString(),
        ":user_id" => $userId,
        ":token_hash" => tokenHash($accessToken),
        ":expires_at" => $accessExpires,
        ":created_at" => $now,
    ]);

    $refreshInsert = $pdo->prepare(
        "INSERT INTO refresh_tokens (id, user_id, token_hash, expires_at, revoked_at, replaced_by_hash, created_at)
         VALUES (:id, :user_id, :token_hash, :expires_at, NULL, NULL, :created_at)",
    );
    $refreshInsert->execute([
        ":id" => Uuid::uuid7()->toString(),
        ":user_id" => $userId,
        ":token_hash" => tokenHash($refreshToken),
        ":expires_at" => $refreshExpires,
        ":created_at" => $now,
    ]);

    return [
        "access_token" => $accessToken,
        "refresh_token" => $refreshToken,
        "access_expires_at" => $accessExpires,
        "refresh_expires_at" => $refreshExpires,
    ];
}

function rotateRefreshToken(PDO $pdo, string $refreshToken): ?array
{
    $hash = tokenHash($refreshToken);
    $stmt = $pdo->prepare(
        "SELECT * FROM refresh_tokens
         WHERE token_hash = :token_hash
           AND revoked_at IS NULL
           AND expires_at > :now
         LIMIT 1",
    );
    $stmt->execute([
        ":token_hash" => $hash,
        ":now" => utcNow(),
    ]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    $now = utcNow();
    $newPair = issueTokenPair($pdo, $row["user_id"]);

    $update = $pdo->prepare(
        "UPDATE refresh_tokens
         SET revoked_at = :revoked_at, replaced_by_hash = :replaced_by_hash
         WHERE token_hash = :token_hash",
    );
    $update->execute([
        ":revoked_at" => $now,
        ":replaced_by_hash" => tokenHash($newPair["refresh_token"]),
        ":token_hash" => $hash,
    ]);

    return $newPair;
}

function revokeRefresh(PDO $pdo, string $refreshToken): void
{
    $stmt = $pdo->prepare("UPDATE refresh_tokens SET revoked_at = :revoked WHERE token_hash = :hash AND revoked_at IS NULL");
    $stmt->execute([
        ":revoked" => utcNow(),
        ":hash" => tokenHash($refreshToken),
    ]);
}

function revokeAccess(PDO $pdo, string $accessToken): void
{
    $stmt = $pdo->prepare("UPDATE access_tokens SET revoked_at = :revoked WHERE token_hash = :hash AND revoked_at IS NULL");
    $stmt->execute([
        ":revoked" => utcNow(),
        ":hash" => tokenHash($accessToken),
    ]);
}

function fetchGithubIdentity(string $code, string $redirectUri, ?string $codeVerifier): array
{
    $clientId = $_ENV["GITHUB_CLIENT_ID"] ?? "";
    $clientSecret = $_ENV["GITHUB_CLIENT_SECRET"] ?? "";

    if ($clientId === "" || $clientSecret === "") {
        throw new RuntimeException("GitHub OAuth is not configured.");
    }

    $http = new Client([
        "timeout" => 15,
    ]);

    $tokenPayload = [
        "client_id" => $clientId,
        "client_secret" => $clientSecret,
        "code" => $code,
        "redirect_uri" => $redirectUri,
    ];
    if ($codeVerifier !== null && $codeVerifier !== "") {
        $tokenPayload["code_verifier"] = $codeVerifier;
    }

    $tokenResponse = $http->post("https://github.com/login/oauth/access_token", [
        "headers" => [
            "Accept" => "application/json",
        ],
        "form_params" => $tokenPayload,
    ]);

    $tokenJson = json_decode((string) $tokenResponse->getBody(), true);
    $githubToken = $tokenJson["access_token"] ?? null;

    if (!$githubToken) {
        throw new RuntimeException("Failed to exchange GitHub authorization code.");
    }

    $userResponse = $http->get("https://api.github.com/user", [
        "headers" => [
            "Authorization" => "Bearer " . $githubToken,
            "Accept" => "application/vnd.github+json",
            "User-Agent" => "insighta-backend",
        ],
    ]);
    $user = json_decode((string) $userResponse->getBody(), true);

    $email = $user["email"] ?? null;
    if (!$email) {
        $emailsResponse = $http->get("https://api.github.com/user/emails", [
            "headers" => [
                "Authorization" => "Bearer " . $githubToken,
                "Accept" => "application/vnd.github+json",
                "User-Agent" => "insighta-backend",
            ],
        ]);

        $emails = json_decode((string) $emailsResponse->getBody(), true);
        foreach ($emails as $row) {
            if (($row["primary"] ?? false) === true) {
                $email = $row["email"];
                break;
            }
        }
        if (!$email && isset($emails[0]["email"])) {
            $email = $emails[0]["email"];
        }
    }

    if (!$email) {
        throw new RuntimeException("Unable to retrieve GitHub email.");
    }

    return [
        "github_id" => (string) ($user["id"] ?? ""),
        "username" => (string) ($user["login"] ?? ""),
        "email" => strtolower((string) $email),
        "avatar_url" => (string) ($user["avatar_url"] ?? ""),
    ];
}

function upsertUserFromGithub(PDO $pdo, array $githubUser): array
{
    $adminEmail = strtolower(trim($_ENV["ADMIN_EMAIL"] ?? "akoshodi@gmail.com"));
    $roleFromEmail = $githubUser["email"] === $adminEmail ? "admin" : "analyst";

    $stmt = $pdo->prepare("SELECT * FROM users WHERE github_id = :github_id OR LOWER(email) = LOWER(:email) LIMIT 1");
    $stmt->execute([
        ":github_id" => $githubUser["github_id"],
        ":email" => $githubUser["email"],
    ]);
    $existing = $stmt->fetch();

    if ($existing) {
        $role = $existing["role"];
        if ($githubUser["email"] === $adminEmail) {
            $role = "admin";
        }

        $update = $pdo->prepare(
            "UPDATE users
             SET github_id = :github_id,
                 username = :username,
                 email = :email,
                 avatar_url = :avatar_url,
                 role = :role,
                 last_login_at = :last_login_at
             WHERE id = :id",
        );
        $update->execute([
            ":github_id" => $githubUser["github_id"],
            ":username" => $githubUser["username"],
            ":email" => $githubUser["email"],
            ":avatar_url" => $githubUser["avatar_url"],
            ":role" => $role,
            ":last_login_at" => utcNow(),
            ":id" => $existing["id"],
        ]);

        $refetch = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $refetch->execute([$existing["id"]]);
        return $refetch->fetch();
    }

    $insert = $pdo->prepare(
        "INSERT INTO users (id, github_id, username, email, avatar_url, role, is_active, last_login_at, created_at)
         VALUES (:id, :github_id, :username, :email, :avatar_url, :role, 1, :last_login_at, :created_at)",
    );
    $id = Uuid::uuid7()->toString();
    $insert->execute([
        ":id" => $id,
        ":github_id" => $githubUser["github_id"],
        ":username" => $githubUser["username"],
        ":email" => $githubUser["email"],
        ":avatar_url" => $githubUser["avatar_url"],
        ":role" => $roleFromEmail,
        ":last_login_at" => utcNow(),
        ":created_at" => utcNow(),
    ]);

    $fetch = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $fetch->execute([$id]);
    return $fetch->fetch();
}

function userPayload(array $user): array
{
    return [
        "id" => $user["id"],
        "github_id" => $user["github_id"],
        "username" => $user["username"],
        "email" => $user["email"],
        "avatar_url" => $user["avatar_url"],
        "role" => $user["role"],
        "is_active" => (bool) $user["is_active"],
        "last_login_at" => $user["last_login_at"],
        "created_at" => $user["created_at"],
    ];
}

function validateProfileVersionHeader(Request $request): bool
{
    return trim($request->getHeaderLine("X-API-Version")) === "1";
}

function logRequest(Request $request, Response $response, int $durationMs): void
{
    $logDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . "logs";
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $line = sprintf(
        "[%s] %s %s %d %dms%s",
        utcNow(),
        strtoupper($request->getMethod()),
        $request->getUri()->getPath(),
        $response->getStatusCode(),
        $durationMs,
        PHP_EOL,
    );
    file_put_contents($logDir . DIRECTORY_SEPARATOR . "requests.log", $line, FILE_APPEND);
}

$app->add(function (Request $request, $handler): Response {
    $started = microtime(true);
    $response = $handler->handle($request);
    $durationMs = (int) round((microtime(true) - $started) * 1000);
    logRequest($request, $response, $durationMs);
    return $response;
});

$app->add(function (Request $request, $handler): Response {
    $response = $handler->handle($request);
    $origin = $_ENV["WEB_ORIGIN"] ?? "*";

    $response = $response
        ->withHeader("Access-Control-Allow-Origin", $origin)
        ->withHeader("Access-Control-Allow-Methods", "GET, POST, PATCH, DELETE, OPTIONS")
        ->withHeader("Access-Control-Allow-Headers", "Content-Type, Accept, Authorization, X-API-Version, X-CSRF-Token");

    if ($origin !== "*") {
        $response = $response->withHeader("Access-Control-Allow-Credentials", "true");
    }

    return $response;
});

$app->options("/{routes:.+}", function (Request $request, Response $response): Response {
    return $response->withStatus(200);
});

$app->add(function (Request $request, $handler) use ($container): Response {
    $path = $request->getUri()->getPath();
    $method = strtoupper($request->getMethod());
    $pdo = $container->get(Database::class)->getPdo();
    $ip = $request->getServerParams()["REMOTE_ADDR"] ?? "unknown";

    if (str_starts_with($path, "/auth/")) {
        if (!enforceRateLimit($pdo, "auth:" . $ip, 10)) {
            return errorJson(new Slim\Psr7\Response(), "Too many requests", 429);
        }
    }

    if (!str_starts_with($path, "/api/")) {
        return $handler->handle($request);
    }

    $accessToken = getBearerToken($request) ?? ($request->getCookieParams()["access_token"] ?? null);
    if (!$accessToken) {
        return errorJson(new Slim\Psr7\Response(), "Authentication required", 401);
    }

    $user = authenticateUser($pdo, $accessToken);
    if (!$user) {
        return errorJson(new Slim\Psr7\Response(), "Invalid or expired access token", 401);
    }
    if ((int) $user["is_active"] !== 1) {
        return errorJson(new Slim\Psr7\Response(), "User is inactive", 403);
    }

    if (!enforceRateLimit($pdo, "api:" . $user["id"], 60)) {
        return errorJson(new Slim\Psr7\Response(), "Too many requests", 429);
    }

    if (str_starts_with($path, "/api/profiles") && !validateProfileVersionHeader($request)) {
        return errorJson(new Slim\Psr7\Response(), "API version header required", 400);
    }

    $isMutating = in_array($method, ["POST", "PATCH", "DELETE"], true);
    $usingCookieAuth = getBearerToken($request) === null;
    if ($isMutating && $usingCookieAuth) {
        $csrfCookie = $request->getCookieParams()["csrf_token"] ?? "";
        $csrfHeader = $request->getHeaderLine("X-CSRF-Token");
        if ($csrfCookie === "" || !hash_equals($csrfCookie, $csrfHeader)) {
            return errorJson(new Slim\Psr7\Response(), "CSRF token mismatch", 403);
        }
    }

    $adminOnly = false;
    if ($path === "/api/profiles" && $method === "POST") {
        $adminOnly = true;
    }
    if (preg_match('#^/api/profiles/[^/]+$#', $path) && $method === "DELETE") {
        $adminOnly = true;
    }
    if (str_starts_with($path, "/api/admin/")) {
        $adminOnly = true;
    }

    if ($adminOnly && $user["role"] !== "admin") {
        return errorJson(new Slim\Psr7\Response(), "Forbidden", 403);
    }

    return $handler->handle($request->withAttribute("auth_user", $user));
});

$app->get("/auth/github", function (Request $request, Response $response) use ($container): Response {
    $pdo = $container->get(Database::class)->getPdo();
    $q = $request->getQueryParams();

    $mode = strtolower((string) ($q["mode"] ?? "web"));
    if (!in_array($mode, ["web", "cli"], true)) {
        return errorJson($response, "Invalid mode", 400);
    }

    $state = (string) ($q["state"] ?? randomToken(24));
    $codeChallenge = (string) ($q["code_challenge"] ?? "");
    $codeVerifier = (string) ($q["code_verifier"] ?? "");

    if ($codeChallenge === "") {
        $codeVerifier = randomToken(48);
        $codeChallenge = pkceChallenge($codeVerifier);
    }

    $defaultRedirect = $_ENV["GITHUB_REDIRECT_URI"] ?? "";
    $redirectUri = (string) ($q["redirect_uri"] ?? $defaultRedirect);

    if ($redirectUri === "") {
        return errorJson($response, "redirect_uri is required", 400);
    }

    $insert = $pdo->prepare(
        "INSERT OR REPLACE INTO oauth_states (state, code_challenge, mode, redirect_uri, code_verifier, expires_at, used_at, created_at)
         VALUES (:state, :code_challenge, :mode, :redirect_uri, :code_verifier, :expires_at, NULL, :created_at)",
    );
    $insert->execute([
        ":state" => $state,
        ":code_challenge" => $codeChallenge,
        ":mode" => $mode,
        ":redirect_uri" => $redirectUri,
        ":code_verifier" => $codeVerifier,
        ":expires_at" => utcPlusMinutes(5),
        ":created_at" => utcNow(),
    ]);

    $params = [
        "client_id" => $_ENV["GITHUB_CLIENT_ID"] ?? "",
        "redirect_uri" => $redirectUri,
        "scope" => "read:user user:email",
        "state" => $state,
        "code_challenge" => $codeChallenge,
        "code_challenge_method" => "S256",
    ];

    $authUrl = "https://github.com/login/oauth/authorize?" . http_build_query($params);

    if ($mode === "cli") {
        return jsonResponse($response, [
            "status" => "success",
            "data" => [
                "authorize_url" => $authUrl,
                "state" => $state,
                "code_verifier" => $codeVerifier,
            ],
        ]);
    }

    $response = $response->withStatus(302)->withHeader("Location", $authUrl);
    $response = appendCookieHeader($response, "oauth_state", $state, 300, true);
    if ($codeVerifier !== "") {
        $response = appendCookieHeader($response, "pkce_verifier", $codeVerifier, 300, true);
    }

    return $response;
});

$app->post("/auth/github/exchange", function (Request $request, Response $response) use ($container): Response {
    $pdo = $container->get(Database::class)->getPdo();
    $body = (array) $request->getParsedBody();

    $code = trim((string) ($body["code"] ?? ""));
    $state = trim((string) ($body["state"] ?? ""));
    $codeVerifier = trim((string) ($body["code_verifier"] ?? ""));

    if ($code === "" || $state === "" || $codeVerifier === "") {
        return errorJson($response, "code, state and code_verifier are required", 400);
    }

    $stateStmt = $pdo->prepare("SELECT * FROM oauth_states WHERE state = ? LIMIT 1");
    $stateStmt->execute([$state]);
    $oauthState = $stateStmt->fetch();

    if (!$oauthState || $oauthState["used_at"] !== null || $oauthState["expires_at"] <= utcNow()) {
        return errorJson($response, "Invalid or expired state", 400);
    }

    if (($oauthState["mode"] ?? "") !== "cli") {
        return errorJson($response, "State is not valid for CLI exchange", 400);
    }

    $expectedChallenge = $oauthState["code_challenge"] ?? "";
    if ($expectedChallenge !== "" && !hash_equals($expectedChallenge, pkceChallenge($codeVerifier))) {
        return errorJson($response, "Invalid PKCE verifier", 400);
    }

    try {
        $githubUser = fetchGithubIdentity($code, (string) $oauthState["redirect_uri"], $codeVerifier);
        $user = upsertUserFromGithub($pdo, $githubUser);
        $pair = issueTokenPair($pdo, $user["id"]);
    } catch (Throwable $e) {
        return errorJson($response, $e->getMessage(), 502);
    }

    $markUsed = $pdo->prepare("UPDATE oauth_states SET used_at = ? WHERE state = ?");
    $markUsed->execute([utcNow(), $state]);

    return jsonResponse($response, [
        "status" => "success",
        "access_token" => $pair["access_token"],
        "refresh_token" => $pair["refresh_token"],
        "user" => userPayload($user),
    ]);
});

$app->get("/auth/github/callback", function (Request $request, Response $response) use ($container): Response {
    $pdo = $container->get(Database::class)->getPdo();
    $q = $request->getQueryParams();

    $code = trim((string) ($q["code"] ?? ""));
    $state = trim((string) ($q["state"] ?? ""));

    if ($code === "" || $state === "") {
        return errorJson($response, "Missing callback parameters", 400);
    }

    $stateStmt = $pdo->prepare("SELECT * FROM oauth_states WHERE state = ? LIMIT 1");
    $stateStmt->execute([$state]);
    $oauthState = $stateStmt->fetch();

    if (!$oauthState || $oauthState["used_at"] !== null || $oauthState["expires_at"] <= utcNow()) {
        return errorJson($response, "Invalid or expired state", 400);
    }

    $cookieState = (string) ($request->getCookieParams()["oauth_state"] ?? "");
    if ($cookieState !== "" && !hash_equals($cookieState, $state)) {
        return errorJson($response, "State mismatch", 400);
    }

    $verifier = (string) ($request->getCookieParams()["pkce_verifier"] ?? ($oauthState["code_verifier"] ?? ""));
    if ($verifier === "") {
        return errorJson($response, "PKCE verifier is missing", 400);
    }

    try {
        $githubUser = fetchGithubIdentity($code, (string) $oauthState["redirect_uri"], $verifier);
        $user = upsertUserFromGithub($pdo, $githubUser);
        $pair = issueTokenPair($pdo, $user["id"]);
    } catch (Throwable $e) {
        return errorJson($response, $e->getMessage(), 502);
    }

    $markUsed = $pdo->prepare("UPDATE oauth_states SET used_at = ? WHERE state = ?");
    $markUsed->execute([utcNow(), $state]);

    $csrfToken = randomToken(24);
    $response = appendCookieHeader($response, "access_token", $pair["access_token"], 180, true);
    $response = appendCookieHeader($response, "refresh_token", $pair["refresh_token"], 300, true);
    $response = appendCookieHeader($response, "csrf_token", $csrfToken, 300, false);
    $response = appendExpiredCookieHeader($response, "oauth_state");
    $response = appendExpiredCookieHeader($response, "pkce_verifier");

    return jsonResponse($response, [
        "status" => "success",
        "message" => "Authenticated",
        "user" => userPayload($user),
    ]);
});

$app->post("/auth/refresh", function (Request $request, Response $response) use ($container): Response {
    $pdo = $container->get(Database::class)->getPdo();
    $body = (array) $request->getParsedBody();
    $cookieRefresh = $request->getCookieParams()["refresh_token"] ?? null;
    $refreshToken = (string) ($body["refresh_token"] ?? $cookieRefresh ?? "");

    if ($refreshToken === "") {
        return errorJson($response, "refresh_token is required", 400);
    }

    $pair = rotateRefreshToken($pdo, $refreshToken);
    if (!$pair) {
        return errorJson($response, "Invalid or expired refresh token", 401);
    }

    if ($cookieRefresh !== null) {
        $response = appendCookieHeader($response, "access_token", $pair["access_token"], 180, true);
        $response = appendCookieHeader($response, "refresh_token", $pair["refresh_token"], 300, true);
    }

    return jsonResponse($response, [
        "status" => "success",
        "access_token" => $pair["access_token"],
        "refresh_token" => $pair["refresh_token"],
    ]);
});

$app->post("/auth/logout", function (Request $request, Response $response) use ($container): Response {
    $pdo = $container->get(Database::class)->getPdo();
    $body = (array) $request->getParsedBody();

    $refreshToken = (string) ($body["refresh_token"] ?? ($request->getCookieParams()["refresh_token"] ?? ""));
    $accessToken = getBearerToken($request) ?? (string) ($request->getCookieParams()["access_token"] ?? "");

    if ($refreshToken !== "") {
        revokeRefresh($pdo, $refreshToken);
    }
    if ($accessToken !== "") {
        revokeAccess($pdo, $accessToken);
    }

    $response = appendExpiredCookieHeader($response, "access_token");
    $response = appendExpiredCookieHeader($response, "refresh_token");
    $response = appendExpiredCookieHeader($response, "csrf_token");

    return jsonResponse($response, [
        "status" => "success",
        "message" => "Logged out",
    ]);
});

$app->get("/api/me", function (Request $request, Response $response): Response {
    $user = $request->getAttribute("auth_user");
    return jsonResponse($response, [
        "status" => "success",
        "data" => userPayload($user),
    ]);
});

$app->post("/api/profiles", function (Request $request, Response $response) use ($container): Response {
    $body = (array) $request->getParsedBody();
    $rawName = $body["name"] ?? null;

    if ($rawName !== null && !is_string($rawName)) {
        return errorJson($response, "The name field must be a string.", 422);
    }
    if ($rawName === null || trim($rawName) === "") {
        return errorJson($response, "The name field is required.", 400);
    }

    $name = strtolower(trim($rawName));
    $pdo = $container->get(Database::class)->getPdo();

    $stmt = $pdo->prepare("SELECT * FROM profiles WHERE name = ?");
    $stmt->execute([$name]);
    $existing = $stmt->fetch();

    if ($existing) {
        return jsonResponse($response, [
            "status" => "success",
            "message" => "Profile already exists",
            "data" => fullProfile($existing),
        ]);
    }

    try {
        $genderData = $container->get(GenderizeService::class)->classify($name);
        $ageData = $container->get(AgifyService::class)->classify($name);
        $nationalityData = $container->get(NationalizeService::class)->classify($name);
    } catch (RuntimeException $e) {
        return errorJson($response, $e->getMessage(), 502);
    }

    $id = Uuid::uuid7()->toString();
    $createdAt = utcNow();

    $profile = array_merge(
        ["id" => $id, "name" => $name],
        $genderData,
        $ageData,
        $nationalityData,
        ["created_at" => $createdAt],
    );

    $insert = $pdo->prepare(
        "INSERT INTO profiles
            (id, name, gender, gender_probability,
             age, age_group, country_id, country_probability, created_at)
         VALUES
            (:id, :name, :gender, :gender_probability,
             :age, :age_group, :country_id, :country_probability, :created_at)",
    );
    $insert->execute($profile);

    return jsonResponse($response, [
        "status" => "success",
        "data" => fullProfile($profile),
    ], 201);
});

$app->get("/api/profiles", function (Request $request, Response $response) use ($container): Response {
    $p = $request->getQueryParams();
    $pdo = $container->get(Database::class)->getPdo();

    $allowedParams = [
        "gender",
        "age_group",
        "country_id",
        "min_age",
        "max_age",
        "min_gender_probability",
        "min_country_probability",
        "sort_by",
        "order",
        "page",
        "limit",
    ];
    $unknownParams = array_diff(array_keys($p), $allowedParams);
    if (!empty($unknownParams)) {
        return errorJson($response, "Invalid query parameters", 400);
    }

    $allowedSortBy = ["age", "created_at", "gender_probability"];
    $allowedOrder = ["asc", "desc"];

    $sortBy = $p["sort_by"] ?? "created_at";
    $order = strtolower((string) ($p["order"] ?? "desc"));

    if (!in_array($sortBy, $allowedSortBy, true)) {
        return errorJson($response, "Invalid query parameters", 400);
    }
    if (!in_array($order, $allowedOrder, true)) {
        return errorJson($response, "Invalid query parameters", 400);
    }

    if (isset($p["gender"]) && !in_array(strtolower((string) $p["gender"]), ["male", "female"], true)) {
        return errorJson($response, "Invalid query parameters", 400);
    }

    if (isset($p["age_group"]) && !in_array(strtolower((string) $p["age_group"]), ["child", "teenager", "adult", "senior"], true)) {
        return errorJson($response, "Invalid query parameters", 400);
    }

    if (isset($p["country_id"]) && !preg_match('/^[a-zA-Z]{2}$/', (string) $p["country_id"])) {
        return errorJson($response, "Invalid query parameters", 400);
    }

    foreach (["min_age", "max_age", "min_gender_probability", "min_country_probability", "page", "limit"] as $numericParam) {
        if (isset($p[$numericParam]) && !is_numeric((string) $p[$numericParam])) {
            return errorJson($response, "Invalid parameter type", 422);
        }
    }

    $page = max(1, (int) ($p["page"] ?? 1));
    $limit = min(50, max(1, (int) ($p["limit"] ?? 10)));
    $offset = ($page - 1) * $limit;

    $where = [];
    $binds = [];

    if (!empty($p["gender"])) {
        $where[] = "LOWER(gender) = LOWER(:gender)";
        $binds[":gender"] = $p["gender"];
    }
    if (!empty($p["age_group"])) {
        $where[] = "LOWER(age_group) = LOWER(:age_group)";
        $binds[":age_group"] = $p["age_group"];
    }
    if (!empty($p["country_id"])) {
        $where[] = "LOWER(country_id) = LOWER(:country_id)";
        $binds[":country_id"] = $p["country_id"];
    }
    if (isset($p["min_age"])) {
        $where[] = "age >= :min_age";
        $binds[":min_age"] = (int) $p["min_age"];
    }
    if (isset($p["max_age"])) {
        $where[] = "age <= :max_age";
        $binds[":max_age"] = (int) $p["max_age"];
    }
    if (isset($p["min_gender_probability"])) {
        $where[] = "gender_probability >= :min_gender_probability";
        $binds[":min_gender_probability"] = (float) $p["min_gender_probability"];
    }
    if (isset($p["min_country_probability"])) {
        $where[] = "country_probability >= :min_country_probability";
        $binds[":min_country_probability"] = (float) $p["min_country_probability"];
    }

    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM profiles {$whereClause}");
    $countStmt->execute($binds);
    $total = (int) $countStmt->fetchColumn();

    $sql = "
        SELECT * FROM profiles
        {$whereClause}
        ORDER BY {$sortBy} {$order}
        LIMIT :limit OFFSET :offset
    ";
    $stmt = $pdo->prepare($sql);
    foreach ($binds as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
    $stmt->bindValue(":offset", $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $totalPages = $total === 0 ? 0 : (int) ceil($total / $limit);

    return jsonResponse($response, [
        "status" => "success",
        "page" => $page,
        "limit" => $limit,
        "total" => $total,
        "total_pages" => $totalPages,
        "links" => buildPageLinks("/api/profiles", array_diff_key($p, ["page" => 1, "limit" => 1]), $page, $limit, $totalPages),
        "data" => array_map("fullProfile", $rows),
    ]);
});

$app->get("/api/profiles/search", function (Request $request, Response $response) use ($container): Response {
    $params = $request->getQueryParams();

    $allowedParams = ["q", "page", "limit"];
    $unknownParams = array_diff(array_keys($params), $allowedParams);
    if (!empty($unknownParams)) {
        return errorJson($response, "Invalid query parameters", 400);
    }

    foreach (["page", "limit"] as $numericParam) {
        if (isset($params[$numericParam]) && !is_numeric((string) $params[$numericParam])) {
            return errorJson($response, "Invalid parameter type", 422);
        }
    }

    $q = trim((string) ($params["q"] ?? ""));
    if ($q === "") {
        return errorJson($response, "The q parameter is required.", 400);
    }

    try {
        $parser = new NaturalLanguageParser();
        $filters = $parser->parse($q);
    } catch (RuntimeException $e) {
        return errorJson($response, "Unable to interpret query", 400);
    }

    $page = max(1, (int) ($params["page"] ?? 1));
    $limit = min(50, max(1, (int) ($params["limit"] ?? 10)));
    $offset = ($page - 1) * $limit;

    $pdo = $container->get(Database::class)->getPdo();
    $where = [];
    $binds = [];

    if (!empty($filters["gender"])) {
        $where[] = "LOWER(gender) = :gender";
        $binds[":gender"] = strtolower((string) $filters["gender"]);
    }
    if (!empty($filters["age_group"])) {
        $where[] = "LOWER(age_group) = :age_group";
        $binds[":age_group"] = strtolower((string) $filters["age_group"]);
    }
    if (!empty($filters["country_id"])) {
        $where[] = "LOWER(country_id) = LOWER(:country_id)";
        $binds[":country_id"] = $filters["country_id"];
    }
    if (isset($filters["min_age"])) {
        $where[] = "age >= :min_age";
        $binds[":min_age"] = (int) $filters["min_age"];
    }
    if (isset($filters["max_age"])) {
        $where[] = "age <= :max_age";
        $binds[":max_age"] = (int) $filters["max_age"];
    }

    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM profiles {$whereClause}");
    $countStmt->execute($binds);
    $total = (int) $countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT * FROM profiles
         {$whereClause}
         ORDER BY created_at DESC
         LIMIT :limit OFFSET :offset",
    );
    foreach ($binds as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
    $stmt->bindValue(":offset", $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $totalPages = $total === 0 ? 0 : (int) ceil($total / $limit);

    return jsonResponse($response, [
        "status" => "success",
        "page" => $page,
        "limit" => $limit,
        "total" => $total,
        "total_pages" => $totalPages,
        "links" => buildPageLinks("/api/profiles/search", ["q" => $q], $page, $limit, $totalPages),
        "data" => array_map("fullProfile", $rows),
    ]);
});

$app->get("/api/profiles/export", function (Request $request, Response $response) use ($container): Response {
    $p = $request->getQueryParams();
    $pdo = $container->get(Database::class)->getPdo();

    $allowedParams = [
        "format",
        "gender",
        "age_group",
        "country_id",
        "min_age",
        "max_age",
        "min_gender_probability",
        "min_country_probability",
        "sort_by",
        "order",
    ];
    $unknownParams = array_diff(array_keys($p), $allowedParams);
    if (!empty($unknownParams)) {
        return errorJson($response, "Invalid query parameters", 400);
    }

    if (strtolower((string) ($p["format"] ?? "")) !== "csv") {
        return errorJson($response, "Only csv format is supported", 400);
    }

    $allowedSortBy = ["age", "created_at", "gender_probability"];
    $allowedOrder = ["asc", "desc"];
    $sortBy = $p["sort_by"] ?? "created_at";
    $order = strtolower((string) ($p["order"] ?? "desc"));

    if (!in_array($sortBy, $allowedSortBy, true) || !in_array($order, $allowedOrder, true)) {
        return errorJson($response, "Invalid query parameters", 400);
    }

    $where = [];
    $binds = [];

    if (!empty($p["gender"])) {
        $where[] = "LOWER(gender) = LOWER(:gender)";
        $binds[":gender"] = $p["gender"];
    }
    if (!empty($p["age_group"])) {
        $where[] = "LOWER(age_group) = LOWER(:age_group)";
        $binds[":age_group"] = $p["age_group"];
    }
    if (!empty($p["country_id"])) {
        $where[] = "LOWER(country_id) = LOWER(:country_id)";
        $binds[":country_id"] = $p["country_id"];
    }
    if (isset($p["min_age"])) {
        $where[] = "age >= :min_age";
        $binds[":min_age"] = (int) $p["min_age"];
    }
    if (isset($p["max_age"])) {
        $where[] = "age <= :max_age";
        $binds[":max_age"] = (int) $p["max_age"];
    }
    if (isset($p["min_gender_probability"])) {
        $where[] = "gender_probability >= :min_gender_probability";
        $binds[":min_gender_probability"] = (float) $p["min_gender_probability"];
    }
    if (isset($p["min_country_probability"])) {
        $where[] = "country_probability >= :min_country_probability";
        $binds[":min_country_probability"] = (float) $p["min_country_probability"];
    }

    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
    $stmt = $pdo->prepare(
        "SELECT id, name, gender, gender_probability, age, age_group, country_id, country_name, country_probability, created_at
         FROM profiles
         {$whereClause}
         ORDER BY {$sortBy} {$order}",
    );
    $stmt->execute($binds);
    $rows = $stmt->fetchAll();

    $stream = fopen("php://temp", "r+");
    fputcsv($stream, [
        "id",
        "name",
        "gender",
        "gender_probability",
        "age",
        "age_group",
        "country_id",
        "country_name",
        "country_probability",
        "created_at",
    ]);

    foreach ($rows as $row) {
        fputcsv($stream, [
            $row["id"],
            $row["name"],
            $row["gender"],
            $row["gender_probability"],
            $row["age"],
            $row["age_group"],
            $row["country_id"],
            $row["country_name"],
            $row["country_probability"],
            $row["created_at"],
        ]);
    }

    rewind($stream);
    $csv = stream_get_contents($stream);
    fclose($stream);

    $filename = "profiles_" . gmdate("Ymd_His") . ".csv";
    $response->getBody()->write($csv ?: "");

    return $response
        ->withHeader("Content-Type", "text/csv")
        ->withHeader("Content-Disposition", 'attachment; filename="' . $filename . '"')
        ->withStatus(200);
});

$app->get("/api/profiles/{id}", function (Request $request, Response $response, array $args) use ($container): Response {
    $pdo = $container->get(Database::class)->getPdo();
    $stmt = $pdo->prepare("SELECT * FROM profiles WHERE id = ?");
    $stmt->execute([$args["id"]]);
    $row = $stmt->fetch();

    if (!$row) {
        return errorJson($response, "Profile not found", 404);
    }

    return jsonResponse($response, [
        "status" => "success",
        "data" => fullProfile($row),
    ]);
});

$app->delete("/api/profiles/{id}", function (Request $request, Response $response, array $args) use ($container): Response {
    $pdo = $container->get(Database::class)->getPdo();

    $check = $pdo->prepare("SELECT id FROM profiles WHERE id = ?");
    $check->execute([$args["id"]]);
    if (!$check->fetch()) {
        return errorJson($response, "Profile not found", 404);
    }

    $pdo->prepare("DELETE FROM profiles WHERE id = ?")->execute([$args["id"]]);
    return $response->withStatus(204);
});

$app->get("/api/admin/users", function (Request $request, Response $response) use ($container): Response {
    $pdo = $container->get(Database::class)->getPdo();
    $rows = $pdo->query(
        "SELECT id, github_id, username, email, avatar_url, role, is_active, last_login_at, created_at
         FROM users ORDER BY created_at DESC",
    )->fetchAll();

    $rows = array_map(fn(array $u) => userPayload($u), $rows);

    return jsonResponse($response, [
        "status" => "success",
        "data" => $rows,
    ]);
});

$app->patch("/api/admin/users/{id}/role", function (Request $request, Response $response, array $args) use ($container): Response {
    $pdo = $container->get(Database::class)->getPdo();
    $body = (array) $request->getParsedBody();
    $role = strtolower((string) ($body["role"] ?? ""));

    if (!in_array($role, ["admin", "analyst"], true)) {
        return errorJson($response, "role must be admin or analyst", 400);
    }

    $update = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
    $update->execute([$role, $args["id"]]);

    if ($update->rowCount() === 0) {
        return errorJson($response, "User not found", 404);
    }

    return jsonResponse($response, [
        "status" => "success",
        "message" => "Role updated",
    ]);
});

$app->patch("/api/admin/users/{id}/status", function (Request $request, Response $response, array $args) use ($container): Response {
    $pdo = $container->get(Database::class)->getPdo();
    $body = (array) $request->getParsedBody();

    if (!array_key_exists("is_active", $body)) {
        return errorJson($response, "is_active is required", 400);
    }

    $isActive = filter_var($body["is_active"], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    if ($isActive === null) {
        return errorJson($response, "is_active must be boolean", 400);
    }

    $update = $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?");
    $update->execute([$isActive ? 1 : 0, $args["id"]]);

    if ($update->rowCount() === 0) {
        return errorJson($response, "User not found", 404);
    }

    return jsonResponse($response, [
        "status" => "success",
        "message" => "User status updated",
    ]);
});

$app->run();
