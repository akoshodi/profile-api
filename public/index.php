<?php

declare(strict_types=1);

require __DIR__ . "/../vendor/autoload.php";

use App\Database;
use App\Services\AgifyService;
use App\Services\GenderizeService;
use App\Services\NationalizeService;
use DI\ContainerBuilder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ramsey\Uuid\Uuid;
use Slim\Factory\AppFactory;

// ── Environment ───────────────────────────────────────────────────────────────
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . "/..");
$dotenv->safeLoad();

// ── DI Container ──────────────────────────────────────────────────────────────
$builder = new ContainerBuilder();
$builder->addDefinitions([
    Database::class => \DI\create(Database::class),
    GenderizeService::class => \DI\create(GenderizeService::class),
    AgifyService::class => \DI\create(AgifyService::class),
    NationalizeService::class => \DI\create(NationalizeService::class),
]);
$container = $builder->build();

// ── App ───────────────────────────────────────────────────────────────────────
AppFactory::setContainer($container);
$app = AppFactory::create();

// ── Body Parsing Middleware ───────────────────────────────────────────────────
$app->addBodyParsingMiddleware();

// ── Error Middleware ───────────────────────────────────────────────────────────
$errorMiddleware = $app->addErrorMiddleware(false, true, true);

$errorMiddleware->setDefaultErrorHandler(function (
    Request $request,
    \Throwable $exception,
    bool $displayErrorDetails,
) use ($app): Response {
    $response = $app->getResponseFactory()->createResponse();

    $status = 500;
    $message = "Internal server error.";

    if ($exception instanceof \Slim\Exception\HttpNotFoundException) {
        $status = 404;
        $message = "The requested route does not exist.";
    } elseif (
        $exception instanceof \Slim\Exception\HttpMethodNotAllowedException
    ) {
        $status = 405;
        $message = "Method not allowed.";
    }

    $response->getBody()->write(
        json_encode([
            "status" => "error",
            "message" => $message,
        ]),
    );

    return $response
        ->withHeader("Content-Type", "application/json")
        ->withHeader("Access-Control-Allow-Origin", "*")
        ->withStatus($status);
});

// ── CORS Middleware ───────────────────────────────────────────────────────────
$app->add(function (Request $request, $handler): Response {
    $response = $handler->handle($request);
    return $response
        ->withHeader("Access-Control-Allow-Origin", "*")
        ->withHeader(
            "Access-Control-Allow-Methods",
            "GET, POST, DELETE, OPTIONS",
        )
        ->withHeader("Access-Control-Allow-Headers", "Content-Type, Accept");
});

// ── OPTIONS preflight ─────────────────────────────────────────────────────────
$app->options("/{routes:.+}", function (
    Request $request,
    Response $response,
): Response {
    return $response->withStatus(200);
});

// ── Helpers ───────────────────────────────────────────────────────────────────
function json(Response $response, array $data, int $status = 200): Response
{
    $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
    return $response
        ->withHeader("Content-Type", "application/json")
        ->withStatus($status);
}

function utcNow(): string
{
    $dt = new \DateTimeImmutable("now", new \DateTimeZone("UTC"));
    return $dt->format("Y-m-d\TH:i:s\Z");
}

// ── Format helpers ────────────────────────────────────────────────────────────

/**
 * Full profile shape — used for POST and GET /id responses
 */
function fullProfile(array $row): array
{
    return [
        "id" => $row["id"],
        "name" => $row["name"],
        "gender" => $row["gender"],
        "gender_probability" => (float) $row["gender_probability"],
        "sample_size" => (int) $row["sample_size"],
        "age" => (int) $row["age"],
        "age_group" => $row["age_group"],
        "country_id" => $row["country_id"],
        "country_name" => $row["country_name"] ?? null,
        "country_probability" => (float) $row["country_probability"],
        "created_at" => $row["created_at"],
    ];
}

/**
 * Summary shape — used for GET /api/profiles list
 */
function summaryProfile(array $row): array
{
    return [
        "id" => $row["id"],
        "name" => $row["name"],
        "gender" => $row["gender"],
        "age" => (int) $row["age"],
        "age_group" => $row["age_group"],
        "country_id" => $row["country_id"],
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// ROUTES
// ─────────────────────────────────────────────────────────────────────────────

/**
 * POST /api/profiles
 * Accept a name, call all three APIs, store and return the profile.
 * Idempotent — returns existing profile if name already stored.
 */
$app->post("/api/profiles", function (
    Request $request,
    Response $response,
) use ($container): Response {
    $body = $request->getParsedBody();
    $rawName = $body["name"] ?? null;

    // 422 — name key exists but value is not a string (e.g. array)
    if ($rawName !== null && !is_string($rawName)) {
        return json(
            $response,
            [
                "status" => "error",
                "message" => "The name field must be a string.",
            ],
            422,
        );
    }

    // 400 — missing or empty name
    if ($rawName === null || trim($rawName) === "") {
        return json(
            $response,
            [
                "status" => "error",
                "message" => "The name field is required.",
            ],
            400,
        );
    }

    $name = strtolower(trim($rawName));
    $pdo = $container->get(Database::class)->getPdo();

    // ── Idempotency check ─────────────────────────────────────────────────────
    $stmt = $pdo->prepare("SELECT * FROM profiles WHERE name = ?");
    $stmt->execute([$name]);
    $existing = $stmt->fetch();

    if ($existing) {
        return json(
            $response,
            [
                "status" => "success",
                "message" => "Profile already exists",
                "data" => fullProfile($existing),
            ],
            200,
        );
    }

    // ── Call all three external APIs ──────────────────────────────────────────
    try {
        $genderData = $container->get(GenderizeService::class)->classify($name);
        $ageData = $container->get(AgifyService::class)->classify($name);
        $nationalityData = $container
            ->get(NationalizeService::class)
            ->classify($name);
    } catch (\RuntimeException $e) {
        return json(
            $response,
            [
                "status" => "error",
                "message" => $e->getMessage(),
            ],
            502,
        );
    }

    // ── Build and store the profile ───────────────────────────────────────────
    $id = Uuid::uuid7()->toString();
    $createdAt = utcNow();

    $profile = array_merge(
        ["id" => $id, "name" => $name],
        $genderData,
        $ageData,
        $nationalityData,
        ["created_at" => $createdAt],
    );

    $insert = $pdo->prepare("
        INSERT INTO profiles
            (id, name, gender, gender_probability, sample_size,
             age, age_group, country_id, country_probability, created_at)
        VALUES
            (:id, :name, :gender, :gender_probability, :sample_size,
             :age, :age_group, :country_id, :country_probability, :created_at)
    ");
    $insert->execute($profile);

    return json(
        $response,
        [
            "status" => "success",
            "data" => fullProfile($profile),
        ],
        201,
    );
});

// ─────────────────────────────────────────────────────────────────────────────

/**
 * GET /api/profiles
 * Advanced filtering, sorting, and pagination.
 */
$app->get("/api/profiles", function (Request $request, Response $response) use (
    $container,
): Response {
    $p = $request->getQueryParams();
    $pdo = $container->get(Database::class)->getPdo();

    // ── Validate sort_by and order params ─────────────────────────────────────
    $allowedSortBy = ["age", "created_at", "gender_probability"];
    $allowedOrder = ["asc", "desc"];

    $sortBy = $p["sort_by"] ?? "created_at";
    $order = strtolower($p["order"] ?? "desc");

    if (!in_array($sortBy, $allowedSortBy, strict: true)) {
        return json(
            $response,
            [
                "status" => "error",
                "message" => "Invalid query parameters",
            ],
            400,
        );
    }
    if (!in_array($order, $allowedOrder, strict: true)) {
        return json(
            $response,
            [
                "status" => "error",
                "message" => "Invalid query parameters",
            ],
            400,
        );
    }

    // ── Pagination ────────────────────────────────────────────────────────────
    $page = max(1, (int) ($p["page"] ?? 1));
    $limit = min(50, max(1, (int) ($p["limit"] ?? 10)));
    $offset = ($page - 1) * $limit;

    // ── Build WHERE clause ────────────────────────────────────────────────────
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
    if (isset($p["min_age"]) && is_numeric($p["min_age"])) {
        $where[] = "age >= :min_age";
        $binds[":min_age"] = (int) $p["min_age"];
    }
    if (isset($p["max_age"]) && is_numeric($p["max_age"])) {
        $where[] = "age <= :max_age";
        $binds[":max_age"] = (int) $p["max_age"];
    }
    if (
        isset($p["min_gender_probability"]) &&
        is_numeric($p["min_gender_probability"])
    ) {
        $where[] = "gender_probability >= :min_gender_probability";
        $binds[":min_gender_probability"] =
            (float) $p["min_gender_probability"];
    }
    if (
        isset($p["min_country_probability"]) &&
        is_numeric($p["min_country_probability"])
    ) {
        $where[] = "country_probability >= :min_country_probability";
        $binds[":min_country_probability"] =
            (float) $p["min_country_probability"];
    }

    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    // ── Total count (for pagination metadata) ─────────────────────────────────
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM profiles {$whereClause}");
    $countStmt->execute($binds);
    $total = (int) $countStmt->fetchColumn();

    // ── Fetch page ────────────────────────────────────────────────────────────
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

    return json($response, [
        "status" => "success",
        "page" => $page,
        "limit" => $limit,
        "total" => $total,
        "data" => array_map("fullProfile", $rows),
    ]);
});

//  ────────────────────────────────────────────────────────────

/**
 * GET /api/profiles/search?q=young males from nigeria
 * Natural language query endpoint.
 */
$app->get("/api/profiles/search", function (
    Request $request,
    Response $response,
) use ($container): Response {
    $params = $request->getQueryParams();
    $q = trim($params["q"] ?? "");

    if ($q === "") {
        return json(
            $response,
            [
                "status" => "error",
                "message" => "The q parameter is required.",
            ],
            400,
        );
    }

    // ── Parse the query ───────────────────────────────────────────────────────
    try {
        $parser = new \App\Parsers\NaturalLanguageParser();
        $filters = $parser->parse($q);
    } catch (\RuntimeException $e) {
        return json(
            $response,
            [
                "status" => "error",
                "message" => "Unable to interpret query",
            ],
            400,
        );
    }

    // ── Pagination ────────────────────────────────────────────────────────────
    $page = max(1, (int) ($params["page"] ?? 1));
    $limit = min(50, max(1, (int) ($params["limit"] ?? 10)));
    $offset = ($page - 1) * $limit;

    // ── Build WHERE from parsed filters ───────────────────────────────────────
    $pdo = $container->get(Database::class)->getPdo();
    $where = [];
    $binds = [];

    if (!empty($filters["gender"])) {
        $where[] = "LOWER(gender) = :gender";
        $binds[":gender"] = $filters["gender"];
    }
    if (!empty($filters["age_group"])) {
        $where[] = "LOWER(age_group) = :age_group";
        $binds[":age_group"] = $filters["age_group"];
    }
    if (!empty($filters["country_id"])) {
        $where[] = "LOWER(country_id) = LOWER(:country_id)";
        $binds[":country_id"] = $filters["country_id"];
    }
    if (isset($filters["min_age"])) {
        $where[] = "age >= :min_age";
        $binds[":min_age"] = $filters["min_age"];
    }
    if (isset($filters["max_age"])) {
        $where[] = "age <= :max_age";
        $binds[":max_age"] = $filters["max_age"];
    }

    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    // ── Count ─────────────────────────────────────────────────────────────────
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM profiles {$whereClause}");
    $countStmt->execute($binds);
    $total = (int) $countStmt->fetchColumn();

    // ── Fetch ─────────────────────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT * FROM profiles
        {$whereClause}
        ORDER BY created_at DESC
        LIMIT :limit OFFSET :offset
    ");
    foreach ($binds as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
    $stmt->bindValue(":offset", $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    return json($response, [
        "status" => "success",
        "page" => $page,
        "limit" => $limit,
        "total" => $total,
        "data" => array_map("fullProfile", $rows),
    ]);
});

// ─────────────────────────────────────────────────────────────────────────────

/**
 * GET /api/profiles/{id}
 * Return a single profile by UUID.
 */
$app->get("/api/profiles/{id}", function (
    Request $request,
    Response $response,
    array $args,
) use ($container): Response {
    $pdo = $container->get(Database::class)->getPdo();
    $stmt = $pdo->prepare("SELECT * FROM profiles WHERE id = ?");
    $stmt->execute([$args["id"]]);
    $row = $stmt->fetch();

    if (!$row) {
        return json(
            $response,
            [
                "status" => "error",
                "message" => "Profile not found.",
            ],
            404,
        );
    }

    return json($response, [
        "status" => "success",
        "data" => fullProfile($row),
    ]);
});

// ─────────────────────────────────────────────────────────────────────────────

/**
 * DELETE /api/profiles/{id}
 * Delete a profile by UUID. Returns 204 No Content on success.
 */
$app->delete("/api/profiles/{id}", function (
    Request $request,
    Response $response,
    array $args,
) use ($container): Response {
    $pdo = $container->get(Database::class)->getPdo();

    // Check existence first so we can return 404 if not found
    $check = $pdo->prepare("SELECT id FROM profiles WHERE id = ?");
    $check->execute([$args["id"]]);

    if (!$check->fetch()) {
        return json(
            $response,
            [
                "status" => "error",
                "message" => "Profile not found.",
            ],
            404,
        );
    }

    $pdo->prepare("DELETE FROM profiles WHERE id = ?")->execute([$args["id"]]);

    // 204 No Content — no body
    return $response->withStatus(204);
});

// ── Run ───────────────────────────────────────────────────────────────────────
$app->addRoutingMiddleware();
$app->run();
