# RubikREST

Rest interface for Rubik ORM designed for integrations with the Erlenmeyer framework.

RubikREST allows you to instantly expose your Rubik Models as a full-featured REST API with automatic routing, filtering, eager loading, and OpenAPI (Swagger) documentation. It includes a fluent JavaScript client for seamless frontend integration.

## Requirements

- PHP 8.1 or higher
- adaiasmagdiel/erlenmeyer ^5.0
- adaiasmagdiel/rubik ^6.0

## Installation

Install the package via Composer:

```bash
composer require adaiasmagdiel/rubik-rest
```

## Quick Start

### 1. Backend Setup

In your Erlenmeyer bootstrap file (e.g., `index.php`):

```php
use AdaiasMagdiel\Erlenmeyer\App;
use AdaiasMagdiel\RubikREST\RubikREST;
use App\Models\User;
use App\Models\Post;

$app = new App();

// Configure and register resources
RubikREST::configure($app, '/api/v1')
    ->resource('users', User::class)
    ->resource('posts', Post::class, [AuthMiddleware::class]) // Protected route
    ->enableDocs('/api-docs'); // Enable Swagger UI

$app->run();
```

### 2. Security (Mass Assignment)

By default, RubikREST operates in a permissive mode to allow rapid prototyping. For production environments, **you must define the `$fillable` property in your Rubik Models**.

If `$fillable` is defined, the API will strictly filter input data (POST/PATCH) to only allow the specified columns.

```php
namespace App\Models;

use AdaiasMagdiel\Rubik\Model;

class User extends Model
{
    // Only these fields can be created or updated via the API
    public static array $fillable = ['name', 'email', 'bio'];
}
```

## JavaScript Client

This package includes `RubikREST.js`, a zero-dependency, fluent client for consuming the API.

```javascript
import { RubikClient } from "./path/to/RubikREST.js";

const client = new RubikClient("https://api.example.com/v1");

// 1. Fetching data (List)
const { data, count, error } = await client
  .from("users")
  .select("id,name,email")
  .with("posts") // Eager load relationships
  .eq("role", "admin")
  .orderBy("created_at", "desc")
  .page(1, 20)
  .get();

// 2. Fetch single
const user = await client.from("users").find(1);

// 3. Create
await client
  .from("users")
  .create({ name: "John Doe", email: "john@example.com" });

// 4. Update
await client.from("users").update(1, { name: "Jane Doe" });

// 5. Delete
await client.from("users").delete(1);
```

## API Features & Query Parameters

The API supports a rich set of query parameters for the `GET /resource` endpoint.

### Selection & Eager Loading

- **select**: Comma-separated list of columns to return.
  - `GET /users?select=id,name`
- **with**: Comma-separated list of relationships to eager load (prevents N+1 problems).
  - `GET /users?with=posts,profile`

### Pagination & Sorting

- **limit**: Number of records to return.
- **offset**: Number of records to skip.
- **order**: Format `column.direction`.
  - `GET /users?order=age.desc,name.asc`

### Filtering

Filters follow the format `column.operator=value`.

| Operator  | URL Example          | SQL Equivalent                          |
| :-------- | :------------------- | :-------------------------------------- |
| **eq**    | `status.eq=active`   | `status = 'active'`                     |
| **neq**   | `status.neq=banned`  | `status <> 'banned'`                    |
| **gt**    | `age.gt=18`          | `age > 18`                              |
| **gte**   | `age.gte=18`         | `age >= 18`                             |
| **lt**    | `price.lt=100`       | `price < 100`                           |
| **lte**   | `price.lte=100`      | `price <= 100`                          |
| **like**  | `name.like=John*`    | `name LIKE 'John%'`                     |
| **ilike** | `name.ilike=john*`   | `name ILIKE 'john%'` (Case insensitive) |
| **in**    | `id.in=1,2,3`        | `id IN (1, 2, 3)`                       |
| **is**    | `deleted_at.is=null` | `deleted_at IS NULL`                    |

## Documentation (Swagger/OpenAPI)

If enabled via `enableDocs('/docs')`, RubikREST automatically inspects your Rubik Models using reflection to generate an OpenAPI 3.0 specification.

- **UI:** Access `/docs` to see the interactive Swagger UI.
- **JSON:** Access `/docs/openapi.json` to get the raw specification.

The documentation respects the `$fillable` property, marking non-fillable fields as `readOnly`.

## Error Handling

The API returns semantic HTTP status codes:

- **200 OK**: Successful read/update.
- **201 Created**: Successful creation.
- **204 No Content**: Successful deletion.
- **400 Bad Request**: Invalid JSON or invalid query parameter (e.g., requesting a non-existent column).
- **404 Not Found**: Resource ID not found.
- **409 Conflict**: Integrity constraint violation (e.g., duplicate unique entry).
- **500 Internal Server Error**: General application error.

**Note:** In production (when `APP_DEBUG` is not `true`), detailed database error messages are hidden from the client to prevent information disclosure.

## License

RubikREST is licensed under the GPLv3. See the [LICENSE](LICENSE) and the [COPYRIGHT](COPYRIGHT) files for details.
