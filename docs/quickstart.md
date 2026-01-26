# Basic Configuration

See how to configure your REST API in minutes.

## 1. Configuring the Application

In your application entry file (where you instantiate the Erlenmeyer `App`), configure **RubikREST**.

```php
<?php
require 'vendor/autoload.php';

use AdaiasMagdiel\Erlenmeyer\App;
use AdaiasMagdiel\RubikREST\RubikREST;
use App\Models\User;
use App\Models\Product;

$app = new App();

// 1. Start configuration (default prefix: /api)
RubikREST::configure($app, '/api')

    // 2. Register resources
    ->resource('users', User::class)
    ->resource('products', Product::class)

    // 3. (Optional) Enable Swagger UI at /docs
    ->enableDocs('/docs');

$app->run();
```

!!! info "Development with PHP Built-in Server"
If you are running the application using PHP’s built-in server (`php -S`), it is recommended to explicitly define the second parameter of `enableDocs`.

```
This prevents the server from interpreting the JSON specification route as a non-existent static file, which would otherwise result in 404 errors when rendering the Swagger UI.
```

```php
// ...

RubikREST::configure($app, '/api')

    // 2. Register resources
    ->resource('users', User::class)
    ->resource('products', Product::class)

    // 3. (Optional) Enable Swagger UI at /docs
    ->enableDocs('/docs', 'openapi'); // without ".json" to prevent the server from looking for a real file

```

## 2. Creating the Model

Your models must extend the Rubik `Model` class and should ideally define the `$fillable` property for security.

```php
<?php
namespace App\Models;

use AdaiasMagdiel\Rubik\Column;
use AdaiasMagdiel\Rubik\Model;

class User extends Model
{
    protected static string $table = 'users';

    // Defines which fields can be created/updated via the API
    public static array $fillable = ['name', 'email', 'username'];

    // Required to correctly generate the Swagger schema
    public static function fields(): array
    {
        return [
            'id'       => Column::Serial(primaryKey: true),
            'name'     => Column::Varchar(length: 120, notNull: true),
            'username' => Column::Varchar(length: 30, notNull: true, unique: true),
            'email'    => Column::Varchar(length: 255, notNull: true, unique: true),
            // ...
        ];
    }
}
```

## 3. Testing

You can now access:

- **List users:** `GET /api/users`
- **Create user:** `POST /api/users`
- **View documentation:** `GET /docs`
