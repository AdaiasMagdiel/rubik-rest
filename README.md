# RubikREST

RubikREST is a lightweight PostgREST-style REST layer built on top of the [**Erlenmeyer**](https://github.com/AdaiasMagdiel/erlenmeyer) HTTP framework and the [**Rubik ORM**](https://github.com/AdaiasMagdiel/rubik-orm).
It exposes SQL tables as REST resources with filtering, ordering, pagination, joins, and insert operations, using a clean URL/parameter syntax compatible with PostgREST patterns.

RubikREST is ideal when you need a simple, self-hosted, database-driven REST backend without the complexity of a full framework.

---

## Features

- Automatic REST exposure of any SQL table
- GET with:
  - filters (`col.eq`, `col.like`, `col.in`, etc.)
  - logical groups (`and=()`, `or=()`)
  - ordering
  - joins
  - pagination
  - optional `count`
- POST insert operations with PostgREST-compatible behavior
- Strict table-name validation
- Zero configuration beyond creating a Rubik connection
- Erlenmeyer routing integration
- Designed for clean server-side usage or JS client libraries

---

## Requirements

- **PHP 8.1+**
- **Erlenmeyer** (routing + Request/Response)
- **Rubik ORM** (database abstraction)

## Installation

RubikREST is available on Packagist:

```
composer require adaiasmagdiel/rubik-rest
```

---

## Basic Usage

A complete minimal example, using **SQLite**, **Erlenmeyer**, **Rubik ORM**, and a model (`Hero`) that creates its own table:

```php
<?php

use AdaiasMagdiel\Erlenmeyer\App;
use AdaiasMagdiel\Erlenmeyer\Request;
use AdaiasMagdiel\Erlenmeyer\Response;
use AdaiasMagdiel\Rubik\Enum\Driver;
use AdaiasMagdiel\Rubik\Rubik;
use AdaiasMagdiel\RubikREST\RubikREST;
use App\Models\Hero;

require_once __DIR__ . '/vendor/autoload.php';

// --- Rubik Connection
define('DB_PATH', __DIR__ . '/database/data.db');
Rubik::connect(Driver::SQLITE, path: DB_PATH);

// Create model table (optional helper from your model)
Hero::createTable(true);

// --- Erlenmeyer App
$app = new App();

// Enable /api/rest/[table] REST API
RubikREST::init($app);

// Basic route example (optional)
$app->get('/', function (Request $req, Response $res, stdClass $params) {
    return $res->withHtml(file_get_contents(__DIR__ . '/public/index.html'));
});

// Start server
$app->run();
```

After booting the app, every database table becomes available as a REST resource:

```
GET /api/rest/heroes
GET /api/rest/heroes?alter_ego.like=Bruce*
```

---

## GET Examples

### Basic Select

```
GET /api/rest/users
```

### Filtering

```
GET /api/rest/users?name.like=*john*
GET /api/rest/users?age.gt=30
GET /api/rest/users?status.eq=active
```

### IN / NOT IN

```
GET /api/rest/products?id.in=(10,11,12)
GET /api/rest/products?id.notin=(1,2,3)
```

### AND / OR groups

```
GET /api/rest/users?and=(age.gte.18,status.eq.active)
GET /api/rest/users?or=(type.eq.admin,type.eq.manager)
```

### Ordering / Pagination

```
GET /api/rest/users?order=name.asc&limit=10&offset=20
```

### Joining

```
GET /api/rest/orders?join=customers:orders.customer_id=customers.id
```

### Counting

```
GET /api/rest/users?count
```

---

## POST Example

### Insert one row

```
POST /api/rest/users
Content-Type: application/json

{
  "name": "John Doe",
  "email": "john@example.com"
}
```

### Insert multiple rows

```
POST /api/rest/users?count
[
  { "name": "A" },
  { "name": "B" }
]
```

---

## Security Notes

- Table names are strictly validated (`^[a-zA-Z0-9_]+$`).
- No dynamic SQL injection points.
- Operators are whitelisted and normalized.

---

## License

RubikREST is released under the **GNU General Public License v3.0**.

See `LICENSE` for details.
