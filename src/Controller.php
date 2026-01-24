<?php

namespace AdaiasMagdiel\RubikREST;

use AdaiasMagdiel\Erlenmeyer\Request;
use AdaiasMagdiel\Erlenmeyer\Response;
use AdaiasMagdiel\Rubik\Model;
use AdaiasMagdiel\Rubik\Query;
use PDOException;
use Throwable;

class Controller
{
    private string $modelClass;

    public function __construct(string $modelClass)
    {
        $this->modelClass = $modelClass;
    }

    /**
     * Filters input data based on the Model's $fillable property.
     * Provides security against Mass Assignment vulnerabilities.
     * 
     * @param array $data The raw input data.
     * @return array The filtered data.
     */
    private function filterData(array $data): array
    {
        // Checks if the static property $fillable exists and is populated
        if (property_exists($this->modelClass, 'fillable')) {
            $allowed = $this->modelClass::$fillable;
            if (is_array($allowed) && !empty($allowed)) {
                // Returns only the keys that are present in the $allowed array
                return array_intersect_key($data, array_flip($allowed));
            }
        }

        // PERMISSIVE MODE: If $fillable is not defined, allow everything.
        // In production, it is highly recommended to use $fillable in your Models.
        return $data;
    }

    /**
     * Translates backend exceptions into semantic HTTP responses.
     */
    private function handleException(Throwable $e, Response $res): Response
    {
        // 1. Handle Database Specific Errors
        if ($e instanceof PDOException) {
            $code = (string)$e->getCode(); // SQLState

            // 23000: Integrity constraint violation (Duplicate entry, Foreign key missing)
            if ($code === '23000') {
                return $res->setStatusCode(409)->withJson([
                    'error' => 'Conflict: Integrity constraint violation (e.g., duplicate entry or invalid reference).'
                ]);
            }

            // 42S22: Column not found (User requested invalid filter/order/select)
            if ($code === '42S22') {
                return $res->setStatusCode(400)->withJson([
                    'error' => 'Bad Request: Invalid column referenced in query.'
                ]);
            }
        }

        // 2. Default / Server Errors
        $isDebug = ($_ENV['APP_DEBUG'] ?? $_ENV['DEBUG'] ?? 'false') === 'true';

        // Log the real error internally regardless of environment
        // error_log($e->getMessage()); 

        $message = $isDebug ? $e->getMessage() : 'Internal Server Error';

        // If the exception carries a valid HTTP code (e.g. LogicException with code 403), use it
        $status = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;

        return $res->setStatusCode((int)$status)->withJson([
            'data' => null,
            'count' => null,
            'error' => $message
        ]);
    }

    /**
     * GET /resource
     * Handles listing with filtering, sorting, pagination, field selection, and eager loading.
     */
    public function index(Request $req, Response $res)
    {
        try {
            /** @var Query $query */
            $query = $this->modelClass::query();
            $params = $req->getQueryParams();

            // 1. SELECT (Fields)
            if (isset($params['select']) && !empty($params['select'])) {
                $cols = explode(',', $params['select']);
                $query->select(array_map('trim', $cols));
                unset($params['select']);
            }

            // 2. EAGER LOADING (With) - NEW FEATURE
            if (isset($params['with']) && !empty($params['with'])) {
                $relations = explode(',', $params['with']);
                $query->with(array_map('trim', $relations));
                unset($params['with']);
            }

            // 3. ORDER BY
            if (isset($params['order']) && !empty($params['order'])) {
                // Expected format: ?order=col1.asc,col2.desc
                $orders = explode(',', $params['order']);
                foreach ($orders as $order) {
                    $parts = explode('.', trim($order));
                    $col = $parts[0] ?? null;
                    $dir = $parts[1] ?? 'asc';
                    if ($col) {
                        $query->orderBy($col, $dir);
                    }
                }
                unset($params['order']);
            }

            // 4. LIMIT & OFFSET
            $limit = isset($params['limit']) ? (int)$params['limit'] : null;
            $offset = isset($params['offset']) ? (int)$params['offset'] : null;

            if ($limit !== null) {
                $query->limit($limit);
                unset($params['limit']);
            }
            if ($offset !== null) {
                $query->offset($offset);
                unset($params['offset']);
            }

            // 5. COUNT REQUEST
            $countRequested = isset($params['count']);
            if ($countRequested) {
                unset($params['count']);
            }

            // 6. FILTERS (Where clauses)
            $this->applyFilters($query, $params);

            // Execute Count if requested (requires a separate query)
            $total = null;
            if ($countRequested) {
                $countQuery = $this->modelClass::query();
                $this->applyFilters($countQuery, $params);
                $total = $countQuery->count();
            }

            $data = $query->all();

            return $res->withJson([
                'data' => $data,
                'count' => $total,
                'error' => null
            ]);
        } catch (Throwable $e) {
            return $this->handleException($e, $res);
        }
    }

    /**
     * GET /resource/[id]
     * Fetch a single resource by ID.
     */
    public function show(Request $req, Response $res, $id)
    {
        /** @var Model|null $model */
        $model = $this->modelClass::find($id);

        if (!$model) {
            return $res->setStatusCode(404)->withJson(['error' => 'Resource not found']);
        }

        return $res->withJson(['data' => $model->toArray()]);
    }

    /**
     * POST /resource
     * Create a new resource.
     */
    public function store(Request $req, Response $res)
    {
        $data = $req->getJson();
        if (!$data) return $res->setStatusCode(400)->withJson(['error' => "Invalid JSON"]);

        try {
            /** @var Model $model */
            $model = new $this->modelClass();

            // APPLY SECURITY FILTER
            $cleanData = $this->filterData($data);

            $model->hydrate($cleanData);

            if ($model->save()) {
                return $res->setStatusCode(201)->withJson(['data' => $model->toArray()]);
            }

            return $res->setStatusCode(500)->withJson(['error' => "Failed to create resource"]);
        } catch (Throwable $e) {
            return $this->handleException($e, $res);
        }
    }

    /**
     * PATCH /resource/[id]
     * Update an existing resource.
     */
    public function update(Request $req, Response $res, $id)
    {
        try {
            /** @var Model|null $model */
            $model = $this->modelClass::find($id);
            if (!$model) return $res->setStatusCode(404)->withJson(['error' => "Not Found"]);

            $data = $req->getJson();
            if (!$data) return $res->setStatusCode(400)->withJson(['error' => "Invalid JSON body"]);

            // APPLY SECURITY FILTER
            $cleanData = $this->filterData($data);

            $model->hydrate($cleanData);

            if ($model->save()) {
                return $res->withJson(['data' => $model->toArray()]);
            }

            return $res->setStatusCode(500)->withJson(['error' => "Update failed"]);
        } catch (Throwable $e) {
            return $this->handleException($e, $res);
        }
    }

    /**
     * DELETE /resource/[id]
     * Delete a resource.
     */
    public function destroy(Request $req, Response $res, $id)
    {
        try {
            /** @var Model|null $model */
            $model = $this->modelClass::find($id);
            if (!$model) return $res->setStatusCode(404)->withJson(['error' => "Not Found"]);

            if ($model->delete()) {
                return $res->setStatusCode(204)->send();
            }

            return $res->setStatusCode(500)->withJson(['error' => "Delete failed"]);
        } catch (Throwable $e) {
            return $this->handleException($e, $res);
        }
    }

    /**
     * Helper to map URL parameters (key.op=val) to Rubik Query methods.
     */
    private function applyFilters(Query $query, array $params): void
    {
        foreach ($params as $key => $value) {
            if (str_contains($key, '.')) {
                [$col, $op] = explode('.', $key, 2);
                $op = strtolower($op);

                switch ($op) {
                    case 'eq':
                        $query->where($col, '=', $value);
                        break;
                    case 'neq':
                        $query->where($col, '<>', $value);
                        break;
                    case 'gt':
                        $query->where($col, '>', $value);
                        break;
                    case 'gte':
                        $query->where($col, '>=', $value);
                        break;
                    case 'lt':
                        $query->where($col, '<', $value);
                        break;
                    case 'lte':
                        $query->where($col, '<=', $value);
                        break;
                    case 'like':
                        // Client is responsible for wildcards, or we assume * -> % replacement
                        $val = str_replace('*', '%', $value);
                        $query->where($col, 'LIKE', $val);
                        break;
                    case 'ilike':
                        $val = str_replace('*', '%', $value);
                        $query->where($col, 'ILIKE', $val);
                        break;
                    case 'in':
                        $vals = explode(',', $value);
                        $query->whereIn($col, $vals);
                        break;
                    case 'not_in':
                    case 'not':
                        // Manual NOT IN implementation using loop
                        $vals = explode(',', $value);
                        foreach ($vals as $v) $query->where($col, '<>', $v);
                        break;
                    case 'is':
                        if ($value === 'null') $query->where($col, 'IS', null);
                        if ($value === 'true') $query->where($col, '=', true);
                        if ($value === 'false') $query->where($col, '=', false);
                        break;
                }
            } else {
                // Default: key=value implies equality
                $query->where($key, '=', $value);
            }
        }
    }
}
