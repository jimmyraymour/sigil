<?php
declare(strict_types=1);

use Sigil\Api\Router;
use Sigil\Util\ApiException;
use Sigil\Util\BasePath;

$app = require __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$respond = static function (int $status, array $data): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
};

$error = static function (ApiException $e): void {
    http_response_code($e->httpStatus());
    if ($e->retryAfter() !== null) {
        header('Retry-After: ' . $e->retryAfter());
    }
    echo json_encode([
        'error' => [
            'code' => $e->errorCode(),
            'message' => $e->getMessage(),
            'details' => $e->details(),
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
};

// Adds a signed, expiring asset URL for web consumption (img tags).
$withAssetUrl = static function (array $render, Sigil\App $app): array {
    $render['asset_url'] = null;
    if (($render['status'] ?? '') === 'succeeded' && !empty($render['output_path'])) {
        $signed = $app->urlSigner()->sign('render:' . $render['id'], (int) $app->config()['asset_url_ttl_seconds']);
        $render['asset_url'] = BasePath::url('/render.php') . '?id=' . rawurlencode($render['id'])
            . '&sig=' . rawurlencode($signed['sig'])
            . '&exp=' . rawurlencode($signed['exp']);
    }
    return $render;
};

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $path = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path = BasePath::strip($path === false || $path === null ? '/' : $path);

    $body = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($body)) {
        $body = [];
    }
    $input = array_merge($_GET, $body);

    $engine = $app->engine();
    $auth = $app->auth();

    $router = new Router();

    // Open endpoint
    $router->add('GET', '#^/api/v1/health$#', static function () use ($respond, $engine): void {
        $respond(200, $engine->health());
    });

    // Briefs
    $router->add('POST', '#^/api/v1/brief$#', static function (array $m, array $input) use ($respond, $engine, $auth): void {
        $row = $auth->requireKey();
        $respond(200, $engine->refineBrief($input, (int) $row['id']));
    });
    $router->add('GET', '#^/api/v1/brief/([a-f0-9-]{36})$#', static function (array $m, array $input) use ($respond, $engine, $auth): void {
        $auth->requireKey();
        $brief = $engine->getBrief($m[0]);
        if ($brief === null) {
            throw ApiException::notFound('Brief not found');
        }
        $respond(200, ['brief' => $brief, 'chain' => $engine->getBriefChain($m[0])]);
    });

    // Iteration (free — local LLM only)
    $router->add('POST', '#^/api/v1/iterate$#', static function (array $m, array $input) use ($respond, $engine, $auth): void {
        $row = $auth->requireKey();
        $respond(200, $engine->iterate($input, (int) $row['id']));
    });

    // Models — what this box can actually run right now, free tier first.
    $router->add('GET', '#^/api/v1/models$#', static function (array $m, array $input) use ($respond, $engine, $auth): void {
        $auth->requireKey();
        $respond(200, ['models' => $engine->models(), 'default' => $engine->registry()->defaultProvider()]);
    });

    // Concepts — free: proposes the distinct ideas for a batch, renders nothing.
    $router->add('POST', '#^/api/v1/concepts$#', static function (array $m, array $input) use ($respond, $engine, $auth): void {
        $row = $auth->requireKey();
        $respond(200, $engine->concepts($input, (int) $row['id']));
    });

    // Renders — one call produces a batch of variants.
    $router->add('POST', '#^/api/v1/render$#', static function (array $m, array $input) use ($respond, $engine, $auth, $withAssetUrl, $app): void {
        $row = $auth->requireKey();
        $batch = $engine->render($input, (int) $row['id']);
        $batch['renders'] = array_map(static fn(array $r): array => $withAssetUrl($r, $app), $batch['renders']);
        $respond(200, $batch);
    });
    $router->add('GET', '#^/api/v1/batch/([a-f0-9-]{36})$#', static function (array $m, array $input) use ($respond, $engine, $auth, $withAssetUrl, $app): void {
        $auth->requireKey();
        $rows = $engine->getBatch($m[0]);
        if ($rows === []) {
            throw ApiException::notFound('Batch not found');
        }
        $respond(200, ['batch_id' => $m[0], 'renders' => array_map(static fn(array $r): array => $withAssetUrl($r, $app), $rows)]);
    });
    $router->add('GET', '#^/api/v1/render/([a-f0-9-]{36})$#', static function (array $m, array $input) use ($respond, $engine, $auth, $withAssetUrl, $app): void {
        $auth->requireKey();
        $render = $engine->getRender($m[0]);
        if ($render === null) {
            throw ApiException::notFound('Render not found');
        }
        $respond(200, $withAssetUrl($render, $app));
    });

    // Library
    $router->add('GET', '#^/api/v1/library$#', static function (array $m, array $input) use ($respond, $engine, $auth, $withAssetUrl, $app): void {
        $auth->requireKey();
        $limit = min(100, max(1, (int) ($input['limit'] ?? 50)));
        $offset = max(0, (int) ($input['offset'] ?? 0));
        $renders = array_map(static fn(array $r): array => $withAssetUrl($r, $app), $engine->listRenders($limit, $offset));
        $respond(200, ['renders' => $renders, 'total' => $engine->countRenders(), 'limit' => $limit, 'offset' => $offset]);
    });

    $router->dispatch($method, $path, $input);
} catch (ApiException $e) {
    $error($e);
} catch (\Throwable $e) {
    $app->logger()->error('api', 'Unhandled error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    http_response_code(500);
    echo json_encode(['error' => ['code' => 'internal_error', 'message' => 'Internal server error', 'details' => []]]);
}
