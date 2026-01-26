# JavaScript Client

RubikREST provides a lightweight, dependency-free JavaScript client class (`RubikClient`) designed to simplify interacting with your API. It features a fluent query builder, automatic JSON parsing, and error handling.

## Installation

There are three ways to include the client in your project.

### 1. Direct Import (ES Modules)

Move `RubikREST.js` or `RubikREST.min.js` from `vendor/adaiasmagdiel/rubik-rest/js/` to your project folder.

```javascript
import { RubikClient } from "./js/RubikREST.js";

const api = new RubikClient("http://localhost:8000/api");
```

### 2. Import via CDN (GitHub Raw)

You can import the minified version directly from the repository.

!!! warning "Production Use"
For high-traffic production environments, consider hosting the file yourself or using a dedicated CDN service (like jsDelivr) pointing to a specific release tag to avoid breaking changes or rate limits.

```javascript
import { RubikClient } from "https://github.com/AdaiasMagdiel/rubik-rest/raw/refs/heads/main/js/RubikREST.min.js";

const api = new RubikClient("https://mysite.com/api");
```

### 3. Classic Script Tag

If you are not using ES Modules, you can include the script in your HTML. The class will be available globally as `window.RubikClient`.

```html
<script src="path/to/RubikREST.min.js"></script>
<script>
  const api = new RubikClient("https://mysite.com/api");
</script>
```

---

## Usage Reference

### Initialization

```javascript
const api = new RubikClient(baseUrl, headers);
```

| Parameter | Type     | Description                                                          |
| :-------- | :------- | :------------------------------------------------------------------- |
| `baseUrl` | `string` | The root URL of your API (e.g., `https://api.example.com`).          |
| `headers` | `object` | Optional global headers (e.g., `{ 'Authorization': 'Bearer ...' }`). |

### Resource Selection

Start a query chain by selecting a resource.

```javascript
const query = api.from("users"); // Points to /users
```

### Query Builder Methods (GET)

These methods allow you to construct the URL parameters for filtering, sorting, and pagination.

| Method               | Arguments              | Description                                                    |
| :------------------- | :--------------------- | :------------------------------------------------------------- |
| `.select(cols)`      | `string[]` or `string` | Columns to return. Ex: `.select(['id', 'name'])`.              |
| `.with(relations)`   | `string[]` or `string` | Eager load relationships. Ex: `.with('posts')`.                |
| `.orderBy(col, dir)` | `string`, `asc\|desc`  | Sort results. Default `asc`.                                   |
| `.page(page, size)`  | `int`, `int`           | Helper for pagination. Sets limit and offset automatically.    |
| `.limit(n)`          | `int`                  | Max records to return.                                         |
| `.offset(n)`         | `int`                  | Records to skip.                                               |
| `.withCount()`       | none                   | Asks the API to return the total record count in the metadata. |

### Filter Shortcuts

All filters correspond to the URL syntax `column.operator=value`.

| Method                 | Example                     | SQL Equivalent                 |
| :--------------------- | :-------------------------- | :----------------------------- |
| `.where(col, op, val)` | `.where('age', 'gt', 18)`   | Generic filter method.         |
| `.eq(col, val)`        | `.eq('status', 'active')`   | `=`                            |
| `.neq(col, val)`       | `.neq('status', 'banned')`  | `<>`                           |
| `.gt(col, val)`        | `.gt('price', 100)`         | `>`                            |
| `.gte(col, val)`       | `.gte('price', 100)`        | `>=`                           |
| `.lt(col, val)`        | `.lt('qty', 10)`            | `<`                            |
| `.lte(col, val)`       | `.lte('qty', 10)`           | `<=`                           |
| `.like(col, val)`      | `.like('name', 'Jo*')`      | `LIKE` (use `*` for wildcard). |
| `.ilike(col, val)`     | `.ilike('name', 'jo*')`     | `ILIKE` (Case insensitive).    |
| `.in(col, array)`      | `.in('id', [1, 2, 3])`      | `IN (...)`                     |
| `.is(col, val)`        | `.is('deleted_at', 'null')` | `IS NULL` / `IS TRUE`          |

### Execution Methods

These methods trigger the actual HTTP request.

#### `get()`

Executes a **GET** request with the built query parameters.

```javascript
const res = await api.from('users').where('active', 'is', 'true').get();

// Response Structure
{
    data: [...],   // Array of records
    count: 150,    // Total count (if .withCount() was used)
    error: null,   // Error object if failed
    status: 200    // HTTP Status code
}
```

#### `find(id)`

Executes a **GET** request for a specific ID.

```javascript
const res = await api.from("users").find(42);
```

#### `create(data)`

Executes a **POST** request to create a record.

```javascript
const res = await api.from("users").create({
  name: "John Doe",
  email: "john@example.com",
});
```

#### `update(id, data)`

Executes a **PATCH** request to update a record.

```javascript
const res = await api.from("users").update(42, {
  name: "John Updated",
});
```

#### `delete(id)`

Executes a **DELETE** request.

```javascript
const res = await api.from("users").delete(42);
```

---

## Debugging and Source Maps

The library comes with `RubikREST.min.js.map`. This file maps the minified code back to the original source.

If you are using the minified version in development, ensure the `.map` file is in the same directory as the `.min.js` file. Modern browsers (Chrome DevTools, Firefox Developer Tools) will automatically detect it and allow you to debug the code as if you were using the original uncompressed file.
