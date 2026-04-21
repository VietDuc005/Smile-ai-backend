<?php

declare(strict_types=1);

require __DIR__ . '/autoload.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-SePay-Signature, X-Casso-Signature');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);

    return;
}

$routeCollection = require __DIR__ . '/routes/api.php';
$route = resolveRoute($routeCollection, (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), normalizedPath());

if ($route === null) {
    respond([
        'status_code' => 404,
        'success' => false,
        'message' => 'Route was not found.',
    ], 404);

    return;
}

$request = requestPayload($route['route_params']);
$controllerAction = $route['route']['action'] ?? null;

if (!is_array($controllerAction) || count($controllerAction) !== 2) {
    respond([
        'status_code' => 500,
        'success' => false,
        'message' => 'Route action is invalid.',
    ], 500);

    return;
}

[$controllerClass, $controllerMethod] = $controllerAction;

try {
    $controller = new $controllerClass();
    $response = buildPipeline($routeCollection['middleware_aliases'] ?? [], $route['route']['middleware'] ?? [], static function (array $nextRequest) use ($controller, $controllerMethod): mixed {
        return $controller->{$controllerMethod}($nextRequest);
    })($request);

    if (!is_array($response)) {
        respond([
            'status_code' => 500,
            'success' => false,
            'message' => 'Controller did not return a valid response array.',
        ], 500);

        return;
    }

    $statusCode = max(100, min(599, (int) ($response['status_code'] ?? 200)));
    respond($response, $statusCode);
} catch (Throwable $exception) {
    respond([
        'status_code' => 500,
        'success' => false,
        'message' => $exception->getMessage(),
        'meta' => [
            'type' => $exception::class,
        ],
    ], 500);
}

function normalizedPath(): string
{
    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $path = parse_url($requestUri, PHP_URL_PATH);
    $normalized = is_string($path) ? trim($path) : '/';

    if ($normalized === '') {
        return '/';
    }

    if ($normalized === '/api') {
        return '/';
    }

    if (str_starts_with($normalized, '/api/')) {
        $normalized = substr($normalized, 4);
    }

    if ($normalized === '') {
        return '/';
    }

    return '/' . trim($normalized, '/');
}

function requestPayload(array $routeParams): array
{
    $body = decodedBody();
    $headers = requestHeaders();
    $query = normalizeArray($_GET ?? []);

    return array_merge($body, [
        'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        'uri' => normalizedPath(),
        'query' => $query,
        'body' => $body,
        'headers' => $headers,
        'route_params' => $routeParams,
        'server' => normalizeArray($_SERVER ?? []),
    ]);
}

function decodedBody(): array
{
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    $raw = file_get_contents('php://input');

    if (is_string($raw) && trim($raw) !== '' && str_contains($contentType, 'application/json')) {
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? normalizeArray($decoded) : [];
    }

    if (!empty($_POST)) {
        return normalizeArray($_POST);
    }

    if (is_string($raw) && trim($raw) !== '') {
        parse_str($raw, $parsed);

        return is_array($parsed) ? normalizeArray($parsed) : [];
    }

    return [];
}

function requestHeaders(): array
{
    if (function_exists('getallheaders')) {
        $headers = getallheaders();

        if (is_array($headers)) {
            return normalizeArray($headers);
        }
    }

    $headers = [];

    foreach ($_SERVER as $key => $value) {
        if (!is_string($key) || !str_starts_with($key, 'HTTP_')) {
            continue;
        }

        $name = strtolower(str_replace('_', '-', substr($key, 5)));
        $headers[$name] = is_scalar($value) ? (string) $value : '';
    }

    if (isset($_SERVER['CONTENT_TYPE'])) {
        $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
    }

    return $headers;
}

function resolveRoute(array $routeCollection, string $method, string $path): ?array
{
    $normalizedMethod = strtoupper(trim($method));

    foreach ($routeCollection as $group => $routes) {
        if ($group === 'middleware_aliases' || !is_array($routes)) {
            continue;
        }

        foreach ($routes as $route) {
            if (!is_array($route) || strtoupper((string) ($route['method'] ?? 'GET')) !== $normalizedMethod) {
                continue;
            }

            $matchedParams = matchRoutePattern((string) ($route['uri'] ?? '/'), $path);

            if ($matchedParams === null) {
                continue;
            }

            return [
                'group' => $group,
                'route' => $route,
                'route_params' => $matchedParams,
            ];
        }
    }

    return null;
}

function matchRoutePattern(string $pattern, string $path): ?array
{
    $patternSegments = explode('/', trim($pattern, '/'));
    $pathSegments = explode('/', trim($path, '/'));

    if ($pattern === '/' && $path === '/') {
        return [];
    }

    if (count($patternSegments) !== count($pathSegments)) {
        return null;
    }

    $params = [];

    foreach ($patternSegments as $index => $segment) {
        $value = $pathSegments[$index] ?? '';

        if (preg_match('/^\{([a-zA-Z0-9_]+)\}$/', $segment, $matches) === 1) {
            $params[$matches[1]] = urldecode($value);
            continue;
        }

        if ($segment !== $value) {
            return null;
        }
    }

    return $params;
}

function buildPipeline(array $aliases, array $middlewareNames, callable $destination): callable
{
    $pipeline = $destination;

    foreach (array_reverse($middlewareNames) as $middlewareName) {
        $definition = $aliases[$middlewareName] ?? null;

        if ($definition === null) {
            continue;
        }

        $pipeline = static function (array $request) use ($definition, $pipeline): mixed {
            $className = is_array($definition) ? (string) ($definition['class'] ?? '') : (string) $definition;
            $arguments = [];

            if ($className === '') {
                return [
                    'status_code' => 500,
                    'success' => false,
                    'message' => 'Middleware definition is invalid.',
                ];
            }

            if (is_array($definition) && array_key_exists('roles', $definition)) {
                $arguments[] = $definition['roles'];
            }

            $middleware = new $className();

            return $middleware->handle($request, $pipeline, ...$arguments);
        };
    }

    return $pipeline;
}

function normalizeArray(array $input): array
{
    $output = [];

    foreach ($input as $key => $value) {
        $output[(string) $key] = is_array($value) ? normalizeArray($value) : $value;
    }

    return $output;
}

function respond(array $payload, int $statusCode): void
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    http_response_code($statusCode);

    // Content-Length + Connection: close cho phép browser/axios nhận đủ response
    // và giải phóng HTTP connection TRƯỚC KHI PHP chạy shutdown functions (vd: gửi email SMTP).
    // Cách này hoạt động trên cả Apache mod_php lẫn PHP-FPM.
    header('Content-Length: ' . strlen($json));
    header('Connection: close');

    // Xả hết output buffer để đảm bảo response được gửi đi.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    echo $json;
    flush();

    // PHP-FPM: kết thúc request ngay lập tức, script tiếp tục chạy trên server.
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
}
