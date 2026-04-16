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
 * Return all profiles with optional filtering.
 * Filters: gender, country_id, age_group (all case-insensitive)
 */
$app->get("/api/profiles", function (Request $request, Response $response) use (
    $container,
): Response {
    $params = $request->getQueryParams();
    $pdo = $container->get(Database::class)->getPdo();

    $where = [];
    $binds = [];

    // Case-insensitive filters using LOWER()
    if (!empty($params["gender"])) {
        $where[] = "LOWER(gender) = LOWER(:gender)";
        $binds[":gender"] = $params["gender"];
    }
    if (!empty($params["country_id"])) {
        $where[] = "LOWER(country_id) = LOWER(:country_id)";
        $binds[":country_id"] = $params["country_id"];
    }
    if (!empty($params["age_group"])) {
        $where[] = "LOWER(age_group) = LOWER(:age_group)";
        $binds[":age_group"] = $params["age_group"];
    }

    $sql = "SELECT * FROM profiles";
    if (!empty($where)) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }
    $sql .= " ORDER BY created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($binds);
    $rows = $stmt->fetchAll();

    return json($response, [
        "status" => "success",
        "count" => count($rows),
        "data" => array_map("summaryProfile", $rows),
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
