# Cookbook & Recipes

This guide contains solutions to common problems and advanced configurations when using RubikREST.

## Creating a Read-Only API

Sometimes you want to expose data without allowing modifications. Instead of creating manual routes or complex middlewares, you can simply use the granular middleware configuration to block write operations.

If you don't provide a middleware that explicitly blocks access, RubikREST routes exist by default. To effectively make an API read-only, you can use a middleware that always returns 405 (Method Not Allowed) for write operations, or simply rely on your authentication middleware to deny access.

However, the cleanest way to conceptually "disable" write routes is to guard them with a middleware that rejects everything.

### 1. The Blocker Middleware

```php
namespace App\Middlewares;

use AdaiasMagdiel\Erlenmeyer\Request;
use AdaiasMagdiel\Erlenmeyer\Response;

class DenyAllMiddleware
{
    public function handle(Request $req, Response $res, callable $next)
    {
        return $res->setStatusCode(405)->withJson([
            'error' => 'This resource is read-only.'
        ]);
    }
}
```

### 2. Configuration

Use the `write` key to apply this middleware only to `POST`, `PATCH`, and `DELETE`.

```php
use App\Middlewares\DenyAllMiddleware;

RubikREST::configure($app)
    ->resource('archive/logs', SystemLog::class, [
        'read'  => [],                 // Public access
        'write' => [DenyAllMiddleware::class] // Block modifications
    ]);
```

## Custom Validation Logic

While RubikREST handles basic data types, business logic validation should happen inside your Model. You can throw exceptions with specific HTTP codes to control the response.

```php
// App/Models/User.php

public function beforeSave(): bool
{
    // Example: Validate password strength
    if (strlen($this->password) < 8) {
        throw new \Exception("Password must be at least 8 characters long.", 400);
    }

    // Example: Prevent changing email after creation
    if (isset($this->_dirty['email'])) {
        throw new \Exception("Email address cannot be changed.", 409);
    }

    return true;
}
```

## Restricting Field Access (Hidden Fields)

To prevent sensitive data (like `password` or `api_token`) from being sent in the API response, override the `toArray` method in your Rubik Model.

```php
// App/Models/User.php

public function toArray(): array
{
    $data = parent::toArray();

    // Remove sensitive fields
    unset($data['password']);
    unset($data['secret_token']);

    return $data;
}
```
