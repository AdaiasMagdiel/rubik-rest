<?php

namespace AdaiasMagdiel\RubikREST;

use AdaiasMagdiel\Erlenmeyer\App;
use AdaiasMagdiel\Erlenmeyer\Request;
use AdaiasMagdiel\Erlenmeyer\Response;
use AdaiasMagdiel\Rubik\Model;
use AdaiasMagdiel\Rubik\Enum\Field;
use Exception;
use InvalidArgumentException;
use stdClass;

class RubikREST
{
    private static App $app;
    private static string $prefix;
    /** @var array<string, string> Maps 'slug' => 'ModelClass' */
    private static array $resources = [];
    /**
     * Configures the REST API instance.
     *
     * @param App $app The application instance.
     * @param string $prefix The URL prefix for API routes (default: '/api').
     * @return static
     */
    public static function configure(App $app, string $prefix = '/api'): static
    {
        self::$app = $app;
        self::$prefix = rtrim($prefix, '/');
        return new static();
    }
    /**
     * Registers a new resource for the API.
     *
     * @param string $slug The URL slug for the resource.
     * @param string $modelClass The fully qualified class name of the model.
     * @param array $middlewares Middlewares to apply. Can be a simple list or granular config (read/write).
     * @return static
     * @throws Exception If the model class does not extend the base Model class.
     */
    public static function resource(string $slug, string $modelClass, array $middlewares = []): static
    {
        if (!is_subclass_of($modelClass, Model::class)) {
            throw new Exception("Class $modelClass must extend AdaiasMagdiel\Rubik\Model");
        }
        self::$resources[$slug] = $modelClass;
        self::registerRoutes($slug, $modelClass, $middlewares);
        return new static();
    }
    /**
     * Enables automatic API documentation via Swagger UI.
     *
     * @param string $path The base URL path for the documentation UI (default: '/api/docs').
     * @param string $specFilename The custom route name for the OpenAPI specification (default: 'openapi.json').
     * @throws InvalidArgumentException If the path or filename contains invalid characters.
     * @return static
     */
    public static function enableDocs(string $path = '/api/docs', string $specFilename = 'openapi.json'): static
    {
        if (!preg_match('/^[a-zA-Z0-9\/\-_.]+$/', $path)) {
            throw new InvalidArgumentException("Invalid characters in documentation path: $path");
        }
        if (!preg_match('/^[a-zA-Z0-9\-_.]+$/', $specFilename)) {
            throw new InvalidArgumentException("Invalid characters in specification filename: $specFilename");
        }
        $path = '/' . ltrim($path, '/');
        $jsonPath = rtrim($path, '/') . '/' . ltrim($specFilename, '/');
        self::$app->get($jsonPath, function (Request $req, Response $res) {
            $spec = self::getOpenApiSpec();
            return $res->withJson($spec);
        });
        self::$app->get($path, function (Request $req, Response $res) use ($jsonPath) {
            $html = self::getSwaggerHTML($jsonPath);
            return $res->withHtml($html);
        });
        return new static();
    }
    /**
     * Registers RESTful routes for a given resource with granular middleware support.
     */
    private static function registerRoutes(string $slug, string $modelClass, array $middlewares): void
    {
        $base = self::$prefix . '/' . $slug;

        // Normalize middlewares (distributes 'read', 'write', 'all' to specific actions)
        $m = self::normalizeMiddlewares($middlewares);

        // GET (Index)
        self::$app->get(
            $base,
            fn(Request $req, Response $res) => (new Controller($modelClass))->index($req, $res),
            $m['index']
        );
        // POST (Store)
        self::$app->post(
            $base,
            fn(Request $req, Response $res) => (new Controller($modelClass))->store($req, $res),
            $m['store']
        );
        // PATCH (Batch Update)
        self::$app->patch(
            $base,
            fn(Request $req, Response $res) => (new Controller($modelClass))->updateBatch($req, $res),
            $m['update']
        );
        // DELETE (Batch Delete)
        self::$app->delete(
            $base,
            fn(Request $req, Response $res) => (new Controller($modelClass))->destroyBatch($req, $res),
            $m['destroy']
        );

        // ID Routes
        self::$app->get(
            $base . '/[id]',
            fn(Request $req, Response $res, stdClass $params) => (new Controller($modelClass))->show($req, $res, $params->id),
            $m['show']
        );
        self::$app->patch(
            $base . '/[id]',
            fn(Request $req, Response $res, stdClass $params) => (new Controller($modelClass))->update($req, $res, $params->id),
            $m['update']
        );
        self::$app->delete(
            $base . '/[id]',
            fn(Request $req, Response $res, stdClass $params) => (new Controller($modelClass))->destroy($req, $res, $params->id),
            $m['destroy']
        );
    }
    /**
     * Distributes middlewares based on configuration keys.
     */
    private static function normalizeMiddlewares(array $input): array
    {
        // If it's a simple indexed array, apply to all routes
        $isList = array_key_exists(0, $input) || empty($input);
        if ($isList) {
            return [
                'index'   => $input,
                'show'    => $input,
                'store'   => $input,
                'update'  => $input,
                'destroy' => $input,
            ];
        }
        // Process aliases
        $defaults = $input['all'] ?? [];
        $read     = array_merge($defaults, $input['read'] ?? []);
        $write    = array_merge($defaults, $input['write'] ?? []);
        return [
            'index'   => array_merge($read, $input['index'] ?? []),
            'show'    => array_merge($read, $input['show'] ?? []),
            'store'   => array_merge($write, $input['store'] ?? []),
            'update'  => array_merge($write, $input['update'] ?? []),
            'destroy' => array_merge($write, $input['destroy'] ?? []),
        ];
    }
    /**
     * Generates the OpenAPI specification array.
     *
     * @return array The OpenAPI spec.
     */
    private static function getOpenApiSpec(): array
    {
        $schemas = [];

        // 1. Define Standard Response Schemas
        $schemas['BatchResult'] = [
            'type' => 'object',
            'properties' => [
                'affected' => ['type' => 'integer', 'description' => 'Number of records affected'],
                'message'  => ['type' => 'string', 'example' => 'Updated 5 records']
            ]
        ];

        $schemas['Error'] = [
            'type' => 'object',
            'properties' => [
                'error' => ['type' => 'string']
            ]
        ];

        // 2. Generate Model Schemas
        $paths = [];
        foreach (self::$resources as $slug => $modelClass) {
            $modelName = class_basename($modelClass);
            $fields = [];
            try {
                $reflection = new \ReflectionMethod($modelClass, 'fields');
                $fields = $reflection->invoke(null);
            } catch (\ReflectionException $e) {
            }
            $fillable = [];
            if (property_exists($modelClass, 'fillable')) {
                $fillable = $modelClass::$fillable;
            }
            $properties = [];
            foreach ($fields as $fieldName => $config) {
                $typeData = self::mapRubikType($config['type'] ?? 'VARCHAR');
                if (!empty($fillable) && !in_array($fieldName, $fillable)) {
                    $typeData['readOnly'] = true;
                }
                $properties[$fieldName] = $typeData;
            }
            $schemas[$modelName] = [
                'type' => 'object',
                'properties' => $properties
            ];

            $basePath = self::$prefix . '/' . $slug;
            $tag = ucfirst($slug);

            // Common Query Parameters
            $queryParameters = [
                ['name' => 'offset', 'in' => 'query', 'schema' => ['type' => 'integer'], 'description' => 'Records to skip'],
                ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer'], 'description' => 'Records per page'],
                ['name' => 'select', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Columns to return (comma-separated)'],
                ['name' => 'with', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Relations to eager load (comma-separated)'],
                ['name' => 'order', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Format: column.direction (e.g. name.asc)'],
                ['name' => 'column.op', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Filters: eq, neq, gt, gte, lt, lte, like, ilike, in (e.g. age.gt=18)']
            ];

            // --- COLLECTION ROUTES (/resource) ---
            $paths[$basePath] = [
                'get' => [
                    'tags' => [$tag],
                    'summary' => "List $slug",
                    'parameters' => $queryParameters,
                    'responses' => [
                        '200' => [
                            'description' => 'Success',
                            'content' => ['application/json' => ['schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'data' => ['type' => 'array', 'items' => ['$ref' => "#/components/schemas/$modelName"]],
                                    'count' => ['type' => 'integer', 'nullable' => true]
                                ]
                            ]]]
                        ]
                    ]
                ],
                'post' => [
                    'tags' => [$tag],
                    'summary' => "Create $slug",
                    'requestBody' => [
                        'content' => ['application/json' => ['schema' => ['$ref' => "#/components/schemas/$modelName"]]]
                    ],
                    'responses' => [
                        '201' => [
                            'description' => 'Created',
                            'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['data' => ['$ref' => "#/components/schemas/$modelName"]]]]]
                        ],
                        '400' => ['description' => 'Bad Request', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
                        '409' => ['description' => 'Conflict', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]]
                    ]
                ],
                'patch' => [
                    'tags' => [$tag],
                    'summary' => "Batch Update $slug",
                    'description' => "Updates multiple records based on filters. **Warning:** If no filters are provided, ALL records will be updated.",
                    'parameters' => [
                        ['name' => 'column.op', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Filters to select records to update (e.g. status.eq=pending)']
                    ],
                    'requestBody' => [
                        'content' => ['application/json' => ['schema' => ['$ref' => "#/components/schemas/$modelName"]]]
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Batch operation successful',
                            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/BatchResult']]]
                        ],
                        '400' => ['description' => 'Bad Request', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]]
                    ]
                ],
                'delete' => [
                    'tags' => [$tag],
                    'summary' => "Batch Delete $slug",
                    'description' => "Deletes multiple records based on filters. **Warning:** If no filters are provided, ALL records will be deleted.",
                    'parameters' => [
                        ['name' => 'column.op', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Filters to select records to delete (e.g. created_at.lt=2023-01-01)']
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Batch operation successful',
                            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/BatchResult']]]
                        ],
                        '400' => ['description' => 'Bad Request', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]]
                    ]
                ]
            ];

            // --- ITEM ROUTES (/resource/{id}) ---
            $paths[$basePath . '/{id}'] = [
                'get' => [
                    'tags' => [$tag],
                    'summary' => "Get $slug by ID",
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
                    'responses' => [
                        '200' => [
                            'description' => 'Success',
                            'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['data' => ['$ref' => "#/components/schemas/$modelName"]]]]]
                        ],
                        '404' => ['description' => 'Not Found', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]]
                    ]
                ],
                'patch' => [
                    'tags' => [$tag],
                    'summary' => "Update $slug",
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
                    'requestBody' => [
                        'content' => ['application/json' => ['schema' => ['$ref' => "#/components/schemas/$modelName"]]]
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Updated',
                            'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['data' => ['$ref' => "#/components/schemas/$modelName"]]]]]
                        ],
                        '404' => ['description' => 'Not Found', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
                        '409' => ['description' => 'Conflict', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]]
                    ]
                ],
                'delete' => [
                    'tags' => [$tag],
                    'summary' => "Delete $slug",
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
                    'responses' => [
                        '204' => ['description' => 'Deleted'],
                        '404' => ['description' => 'Not Found', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]]
                    ]
                ]
            ];
        }
        return [
            'openapi' => '3.0.0',
            'info' => ['title' => 'RubikREST API', 'version' => '1.0.0', 'description' => 'Generated automatically by RubikREST'],
            'paths' => $paths,
            'components' => ['schemas' => $schemas]
        ];
    }
    private static function mapRubikType(mixed $type): array
    {
        if ($type instanceof Field) $type = $type->value;
        $type = strtoupper((string)$type);
        return match ($type) {
            'INTEGER', 'BIGINT', 'SMALLINT', 'TINYINT', 'SERIAL' => ['type' => 'integer'],
            'FLOAT', 'DOUBLE', 'DECIMAL', 'NUMERIC', 'REAL' => ['type' => 'number'],
            'BOOLEAN' => ['type' => 'boolean'],
            'DATETIME', 'TIMESTAMP', 'DATE' => ['type' => 'string', 'format' => 'date-time'],
            'JSON', 'JSONB' => ['type' => 'object'],
            default => ['type' => 'string'],
        };
    }
    private static function getSwaggerHTML(string $specUrl): string
    {
        $templatePath = __DIR__ . '/swagger.html';
        if (!file_exists($templatePath)) return "Swagger HTML template not found.";
        $html = file_get_contents($templatePath);
        return str_replace('{{SPEC_URL}}', $specUrl, $html);
    }
}
if (!function_exists('class_basename')) {
    function class_basename($class)
    {
        $class = is_object($class) ? get_class($class) : $class;
        return basename(str_replace('\\', '/', $class));
    }
}
