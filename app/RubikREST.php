<?php

namespace AdaiasMagdiel\RubikREST;

use AdaiasMagdiel\Erlenmeyer\App;
use AdaiasMagdiel\Erlenmeyer\Request;
use AdaiasMagdiel\Erlenmeyer\Response;
use AdaiasMagdiel\Rubik\Query;
use AdaiasMagdiel\Rubik\Rubik;
use Exception;
use stdClass;
use Throwable;

class RubikREST
{
    /** @var App|null Shared App instance */
    private static ?App $app = null;

    /** @var string Base REST path */
    private static string $baseUrl = '/api/rest';

    /** @var array Allowed HTTP verbs */
    private static array $methods = ['get', 'post', 'patch', 'delete'];

    /**
     * Initializes the REST layer.
     *
     * @throws Exception
     */
    public static function init(App $app, string $baseUrl = '/api/rest')
    {
        self::$app = $app;
        self::$baseUrl = rtrim($baseUrl, '/');

        if (!Rubik::isConnected()) {
            throw new Exception("Rubik connection missing.");
        }

        self::applyRoutes();
    }

    /** Returns the base REST path */
    public static function getBaseUrl()
    {
        return self::$baseUrl;
    }

    /** Returns the dynamic route definition */
    private static function makeUrl()
    {
        return self::$baseUrl . '/[table]';
    }

    /** Registers all REST routes */
    private static function applyRoutes()
    {
        self::$app->any(self::makeUrl(), function (Request $req, Response $res, stdClass $params) {

            $method = strtolower($req->getMethod());

            if (!in_array($method, self::$methods)) {
                return $res->setStatusCode(405)->withJson(["error" => "Method Not Allowed"]);
            }

            $handler = [self::class, $method];

            if (!is_callable($handler)) {
                return $res->setStatusCode(500)->withJson([
                    "error"  => "Handler not implemented",
                    "method" => $method
                ]);
            }

            return $handler($req, $res, $params);
        });
    }

    /**
     * Parses query string operators and transforms them
     * into internal filtering/selection metadata.
     */
    private static function handleQueryParams(): array
    {
        $raw = $_SERVER['QUERY_STRING'] ?? '';

        $pairs = [];
        foreach (explode('&', $raw) as $segment) {
            if ($segment === '') continue;
            [$key, $val] = array_pad(explode('=', $segment, 2), 2, null);
            $pairs[$key] = $val;
        }

        $select = isset($pairs['select'])
            ? explode(',', $pairs['select'])
            : ['*'];

        $select = array_values(array_filter(array_map('trim', $select)));

        $countRequested = array_key_exists('count', $pairs);
        $limit  = isset($pairs['limit'])  ? (int)$pairs['limit']  : null;
        $offset = isset($pairs['offset']) ? (int)$pairs['offset'] : null;

        $order = [];
        if (isset($pairs['order'])) {
            foreach (explode(',', $pairs['order']) as $item) {
                [$col, $dir] = array_pad(explode('.', trim($item)), 2, 'asc');
                $dir = strtolower($dir);
                if (!in_array($dir, ['asc', 'desc'])) $dir = 'asc';
                $order[] = ['column' => $col, 'direction' => $dir];
            }
        }

        // Filters
        $filters = [];
        $reserved = ['select', 'order', 'limit', 'offset', 'count', 'and', 'or', 'join', 'leftJoin', 'rightJoin'];

        foreach ($pairs as $key => $val) {

            if (in_array($key, $reserved, true)) continue;
            if (substr_count($key, '.') < 1) continue;

            $parts = explode('.', $key, 3);

            $col = $parts[0];
            $op  = $parts[1];
            $value = isset($parts[2]) ? $parts[2] : $val;

            if ($op === 'like' || $op === 'ilike') {
                $value = str_replace('*', '%', $value);
            }

            $filters[] = [
                'column' => $col,
                'op'     => strtolower($op),
                'value'  => $value
            ];
        }

        // AND filters
        $andFilters = [];
        if (isset($pairs['and'])) {
            foreach (explode(',', trim($pairs['and'], '()')) as $item) {
                if (substr_count($item, '.') < 2) continue;
                [$col, $op, $val] = explode('.', $item, 3);
                $andFilters[] = ['column' => $col, 'op' => strtolower($op), 'value' => $val];
            }
        }

        // OR filters
        $orFilters = [];
        if (isset($pairs['or'])) {
            foreach (explode(',', trim($pairs['or'], '()')) as $item) {
                if (substr_count($item, '.') < 2) continue;
                [$col, $op, $val] = explode('.', $item, 3);
                $orFilters[] = ['column' => $col, 'op' => strtolower($op), 'value' => $val];
            }
        }

        // Joins
        $joins = [];
        foreach (['join', 'leftJoin', 'rightJoin'] as $joinKey) {
            if (!isset($pairs[$joinKey])) continue;
            foreach (explode(',', $pairs[$joinKey]) as $item) {
                if (!str_contains($item, ':')) continue;
                [$table, $cond] = explode(':', $item, 2);
                [$left, $right] = explode('=', $cond, 2);
                $joins[] = ['type' => $joinKey, 'table' => trim($table), 'left' => trim($left), 'right' => trim($right)];
            }
        }

        return [
            'select'  => $select,
            'count'   => $countRequested,
            'limit'   => $limit,
            'offset'  => $offset,
            'order'   => $order,
            'filters' => $filters,
            'and'     => $andFilters,
            'or'      => $orFilters,
            'joins'   => $joins,
        ];
    }

    /** Returns SQL operator for a logical shorthand */
    private static function mapOp(string $op): string
    {
        return match ($op) {
            'eq'   => '=',
            'neq'  => '<>',
            'gt'   => '>',
            'gte'  => '>=',
            'lt'   => '<',
            'lte'  => '<=',
            'like' => 'LIKE',
            'ilike' => 'ILIKE',
            default => '='
        };
    }

    /** Applies basic filters */
    private static function applyWhere(Query $query, array $filters = []): Query
    {
        foreach ($filters as $f) {

            $col = $f['column'];
            $op  = $f['op'];
            $val = $f['value'];

            switch ($op) {

                case 'eq':
                case 'neq':
                case 'gt':
                case 'gte':
                case 'lt':
                case 'lte':
                    $query->where($col, self::mapOp($op), $val);
                    break;

                case 'like':
                case 'ilike':
                    $query->where($col, strtoupper($op), $val);
                    break;

                case 'is':
                    if ($val === 'null') {
                        $query->where($col, 'IS', null);
                    } elseif ($val === 'not.null') {
                        $query->where($col, 'IS NOT', null);
                    }
                    break;

                case 'in':
                    $vals = array_map('trim', explode(',', trim($val, '()')));
                    $query->whereIn($col, $vals);
                    break;

                case 'not.in':
                case 'not_in':
                case 'notin':
                    $vals = array_map('trim', explode(',', trim($val, '()')));
                    $placeholders = implode(
                        ',',
                        array_map(fn($v) => "'" . addslashes($v) . "'", $vals)
                    );
                    $query->where($col, 'NOT IN', new \AdaiasMagdiel\Rubik\SQL("($placeholders)"));
                    break;
            }
        }

        return $query;
    }

    /** Applies AND/OR filter groups */
    private static function applyLogicalGroups(Query $query, array $andFilters, array $orFilters): Query
    {
        foreach ($andFilters as $f) {
            $query->where($f['column'], self::mapOp($f['op']), $f['value']);
        }

        $first = true;
        foreach ($orFilters as $f) {
            if ($first) {
                $query->where($f['column'], self::mapOp($f['op']), $f['value']);
                $first = false;
            } else {
                $query->orWhere($f['column'], self::mapOp($f['op']), $f['value']);
            }
        }

        return $query;
    }

    /** Applies JOIN definitions */
    private static function applyJoins(Query $query, array $joins): Query
    {
        foreach ($joins as $j) {
            switch ($j['type']) {
                case 'join':
                    $query->join($j['table'], $j['left'], '=', $j['right']);
                    break;
                case 'leftJoin':
                    $query->leftJoin($j['table'], $j['left'], '=', $j['right']);
                    break;
                case 'rightJoin':
                    $query->rightJoin($j['table'], $j['left'], '=', $j['right']);
                    break;
            }
        }

        return $query;
    }

    /**
     * GET handler.
     * Executes SELECT queries based on URL parameters.
     */
    private static function get(Request $req, Response $res, stdClass $params)
    {
        $table = $params->table ?? '';

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            return $res->setStatusCode(400)->withJson([
                "data"  => null,
                "count" => null,
                "error" => "Invalid table name"
            ]);
        }

        $parsed = self::handleQueryParams();

        $query = (new Query())->setTable($table);
        $query = self::applyJoins($query, $parsed['joins']);
        $query = self::applyWhere($query, $parsed['filters']);
        $query = self::applyLogicalGroups($query, $parsed['and'], $parsed['or']);

        $query->select($parsed['select']);

        foreach ($parsed['order'] as $o) {
            $query->orderBy($o['column'], $o['direction']);
        }

        if ($parsed['limit'] !== null)  $query->limit($parsed['limit']);
        if ($parsed['offset'] !== null) $query->offset($parsed['offset']);

        $count = null;
        if ($parsed['count']) {
            $countQuery = (new Query())->setTable($table);
            self::applyJoins($countQuery, $parsed['joins']);
            self::applyWhere($countQuery, $parsed['filters']);
            self::applyLogicalGroups($countQuery, $parsed['and'], $parsed['or']);
            $count = $countQuery->count();
        }

        try {
            $data = $query->all();
            return $res->withJson([
                "data"  => $data,
                "count" => $count,
                "error" => null
            ]);
        } catch (Exception $e) {
            return $res->withJson([
                "data"  => null,
                "count" => $count,
                "error" => $e->getMessage()
            ]);
        }
    }

    /**
     * POST handler.
     * Executes INSERT operations.
     */
    private static function post(Request $req, Response $res, stdClass $params)
    {
        $table = $params->table ?? '';

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            return $res->setStatusCode(400)->withJson([
                "data"  => null,
                "count" => null,
                "error" => "Invalid table name"
            ]);
        }

        try {
            $payload = $req->getJson(true);
        } catch (Throwable $e) {
            return $res->setStatusCode(400)->withJson([
                "data"  => null,
                "count" => null,
                "error" => "Invalid JSON: " . $e->getMessage()
            ]);
        }

        if ($payload === null) {
            return $res->setStatusCode(400)->withJson([
                "data"  => null,
                "count" => null,
                "error" => "Empty JSON body"
            ]);
        }

        $records = is_array($payload) && array_keys($payload) === range(0, count($payload) - 1)
            ? $payload
            : [$payload];

        $parsed = self::handleQueryParams();

        $shouldReturnData =
            isset($_GET['select']) &&
            trim($_GET['select']) !== '';

        try {
            $query = (new Query())->setTable($table);
            $insertedIds = $query->insert($records);
        } catch (Throwable $e) {
            return $res->setStatusCode(500)->withJson([
                "data"  => null,
                "count" => null,
                "error" => "Insert failed: " . $e->getMessage()
            ]);
        }

        $count = $parsed['count'] ? count($records) : null;

        if (!$shouldReturnData) {
            return $res->withJson([
                "data"  => null,
                "count" => $count,
                "error" => null
            ]);
        }

        return $res->withJson([
            "data"  => null,
            "count" => $count,
            "error" => "SELECT handler not implemented yet"
        ]);
    }
}
