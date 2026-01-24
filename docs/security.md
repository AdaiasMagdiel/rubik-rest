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

```php
use App\Middlewares\AuthMiddleware;

RubikREST::configure($app)
    ->resource('admin/logs', Log::class, [AuthMiddleware::class]);
```

## Error Handling

The controller catches exceptions and returns semantic HTTP status codes:

- **400 Bad Request:** Invalid JSON or a non-existent column requested.
- **404 Not Found:** Record not found.
- **409 Conflict:** Integrity violation (e.g., duplicate unique key).
- **500 Internal Server Error:** General errors (details hidden if `APP_DEBUG` is false).
