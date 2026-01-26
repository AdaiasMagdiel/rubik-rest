# Security

## Protection Against Mass Assignment

RubikREST automatically checks the `$fillable` property on your Rubik Models before persisting data to the database (Create and Update operations).

If `$fillable` is defined, **only** the listed fields will be saved. Any other fields sent in the JSON payload will be silently ignored.

```php
// In your Model
public static array $fillable = ['name', 'bio'];

// Malicious payload sent: {"name": "X", "is_admin": true}
// Result: Only "name" is saved. "is_admin" is discarded.
```

!!! warning "Permissive Mode"
If `$fillable` is **not** defined on the Model, RubikREST will accept all submitted fields. It is highly recommended to define `$fillable` in production.

## Middlewares

You can apply middlewares (authentication, logging, rate limiting) to specific resources during registration.

### Basic Usage

Pass a simple array of middleware classes to apply them to **all** routes of that resource.

```php
use App\Middlewares\AuthMiddleware;

RubikREST::configure($app)
    ->resource('admin/logs', Log::class, [AuthMiddleware::class]);
```

### Granular Control (Read vs Write)

You often want public access for reading data but authentication for modifying it. RubikREST allows you to define middlewares for specific action groups using an associative array.

| Key       | Targets                      | Description                                 |
| :-------- | :--------------------------- | :------------------------------------------ |
| `all`     | All Routes                   | Applied to everything.                      |
| `read`    | `index`, `show`              | Applied to **GET** requests.                |
| `write`   | `store`, `update`, `destroy` | Applied to **POST**, **PATCH**, **DELETE**. |
| `index`   | List                         | Specific to the listing route.              |
| `show`    | Detail                       | Specific to the single item route.          |
| `store`   | Create                       | Specific to creation.                       |
| `update`  | Update                       | Specific to update (single and batch).      |
| `destroy` | Delete                       | Specific to deletion (single and batch).    |

#### Example: Public Read, Private Write

```php
RubikREST::configure($app)
    ->resource('products', Product::class, [
        // Everyone can see products
        'read' => [],
        // Only authenticated users can create/edit/delete
        'write' => [AuthMiddleware::class]
    ]);
```

#### Example: Admin-only Delete

```php
RubikREST::configure($app)
    ->resource('users', User::class, [
        // All actions require login
        'all' => [AuthMiddleware::class],
        // Only admins can delete
        'destroy' => [AdminMiddleware::class]
    ]);
```

## Error Handling

The controller catches exceptions and returns semantic HTTP status codes:

- **400 Bad Request:** Invalid JSON or a non-existent column requested.
- **404 Not Found:** Record not found.
- **409 Conflict:** Integrity violation (e.g., duplicate unique key).
- **500 Internal Server Error:** General errors (details hidden if `APP_DEBUG` is false).
