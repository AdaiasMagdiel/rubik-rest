<?php

namespace AdaiasMagdiel\RubikREST;

use AdaiasMagdiel\Erlenmeyer\App;
use AdaiasMagdiel\Erlenmeyer\Request;
use AdaiasMagdiel\Erlenmeyer\Response;
use AdaiasMagdiel\Rubik\Model;
use AdaiasMagdiel\Rubik\Enum\Field;
use Exception;
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
     * @param array $middlewares Optional middlewares to apply to the resource routes.
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
     * @param string $path The URL path where documentation will be served (default: '/docs').
     * @return static
     */
    public static function enableDocs(string $path = '/docs'): static
    {
        $path = '/' . ltrim($path, '/');
        $jsonPath = $path . '/openapi.json';

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
     * Registers RESTful routes for a given resource.
     *
     * @param string $slug The resource slug.
     * @param string $modelClass The model class.
     * @param array $middlewares Middlewares to apply.
     */
    private static function registerRoutes(string $slug, string $modelClass, array $middlewares): void
    {
        $base = self::$prefix . '/' . $slug;

        self::$app->get(
            $base,
            fn(Request $req, Response $res) => (new Controller($modelClass))->index($req, $res),
            $middlewares
        );
        self::$app->post(
            $base,
            fn(Request $req, Response $res) => (new Controller($modelClass))->store($req, $res),
            $middlewares
        );
        self::$app->get(
            $base . '/[id]',
            fn(Request $req, Response $res, stdClass $params) => (new Controller($modelClass))->show($req, $res, $params->id),
            $middlewares
        );
        self::$app->patch(
            $base . '/[id]',
            fn(Request $req, Response $res, stdClass $params) => (new Controller($modelClass))->update($req, $res, $params->id),
            $middlewares
        );
        self::$app->delete(
            $base . '/[id]',
            fn(Request $req, Response $res, stdClass $params) => (new Controller($modelClass))->destroy($req, $res, $params->id),
            $middlewares
        );
    }

    /**
     * Generates the OpenAPI specification array.
     *
     * @return array The OpenAPI spec.
     */
    private static function getOpenApiSpec(): array
    {
        $schemas = [];
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

            // COMMON PARAMETERS
            $queryParameters = [
                ['name' => 'offset', 'in' => 'query', 'schema' => ['type' => 'integer'], 'description' => 'Records to skip'],
                ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer'], 'description' => 'Records per page'],
                ['name' => 'select', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Columns to return (comma-separated)'],
                ['name' => 'with', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Relations to eager load (comma-separated)'],
                ['name' => 'order', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Format: column.direction (e.g. name.asc)'],
                ['name' => 'column.op', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Filters: eq, neq, gt, gte, lt, lte, like, ilike, in']
            ];

            $paths[$basePath] = [
                'get' => [
                    'tags' => [$tag],
                    'summary' => "List $slug",
                    'parameters' => $queryParameters,
                    'responses' => ['200' => ['description' => 'Success']]
                ],
                'post' => [
                    'tags' => [$tag],
                    'summary' => "Create $slug",
                    'requestBody' => [
                        'content' => ['application/json' => ['schema' => ['$ref' => "#/components/schemas/$modelName"]]]
                    ],
                    'responses' => [
                        '201' => ['description' => 'Created'],
                        '400' => ['description' => 'Bad Request'],
                        '409' => ['description' => 'Conflict']
                    ]
                ]
            ];

            $paths[$basePath . '/{id}'] = [
                'get' => [
                    'tags' => [$tag],
                    'summary' => "Get $slug by ID",
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
                    'responses' => [
                        '200' => ['description' => 'Success'],
                        '404' => ['description' => 'Not Found']
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
                        '200' => ['description' => 'Updated'],
                        '404' => ['description' => 'Not Found'],
                        '409' => ['description' => 'Conflict']
                    ]
                ],
                'delete' => [
                    'tags' => [$tag],
                    'summary' => "Delete $slug",
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
                    'responses' => [
                        '204' => ['description' => 'Deleted'],
                        '404' => ['description' => 'Not Found']
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

    /**
     * Maps Rubik field types to OpenAPI data types.
     *
     * @param mixed $type The field type definition.
     * @return array The OpenAPI type definition.
     */
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

    /**
     * Reads the Swagger HTML template and injects the spec URL.
     *
     * @param string $specUrl The URL to the OpenAPI JSON spec.
     * @return string The rendered HTML.
     */
    private static function getSwaggerHTML(string $specUrl): string
    {
        $templatePath = __DIR__ . '/swagger.html';
        if (!file_exists($templatePath)) return "Swagger HTML template not found.";
        $html = file_get_contents($templatePath);
        return str_replace('{{SPEC_URL}}', $specUrl, $html);
    }
}

if (!function_exists('class_basename')) {
    /**
     * Helper to get the class basename.
     *
     * @param string|object $class
     * @return string
     */
    function class_basename($class)
    {
        $class = is_object($class) ? get_class($class) : $class;
        return basename(str_replace('\\', '/', $class));
    }
}
