# API Features

`RubikREST` provides a powerful URL-based query language. All registered resources inherit these features automatically.

## Field Selection

Return only the required data to save bandwidth.

`GET /api/users?select=id,name,email`

## Pagination

Control how many records are returned.

- `limit`: Number of records.
- `offset`: Number of records to skip.

`GET /api/users?limit=10&offset=20`

## Sorting

Sort by one or more columns. Use the `column.direction` format.

`GET /api/products?order=price.desc,name.asc`

## Eager Loading (Relationships)

Automatically load Rubik relationships in the response.

`GET /api/users?with=posts,profile`

## Filters (Where)

You can filter any column using the `column.operator=value` syntax. If the operator is omitted, equality (`eq`) is assumed.

### Available Operators

| Operator                     | URL Example                           | SQL Equivalent             |
| :--------------------------- | :------------------------------------ | :------------------------- |
| **Equality**                 | `status=active` or `status.eq=active` | `WHERE status = 'active'`  |
| **Not equal**                | `status.neq=banned`                   | `WHERE status <> 'banned'` |
| **Greater than**             | `age.gt=18`                           | `WHERE age > 18`           |
| **Greater than or equal**    | `age.gte=18`                          | `WHERE age >= 18`          |
| **Less than**                | `price.lt=100`                        | `WHERE price < 100`        |
| **Less than or equal**       | `price.lte=100`                       | `WHERE price <= 100`       |
| **Like (Search)**            | `name.like=Jo*` (use `*` as wildcard) | `WHERE name LIKE 'Jo%'`    |
| **ILike (Case insensitive)** | `name.ilike=jo*`                      | `WHERE name ILIKE 'jo%'`   |
| **IN (List)**                | `id.in=1,2,3`                         | `WHERE id IN (1, 2, 3)`    |
| **IS (Boolean/Null)**        | `deleted_at.is=null`                  | `WHERE deleted_at IS NULL` |

!!! tip "Wildcard Tip"
In `like` and `ilike` filters, use the asterisk `*` in the URL to represent SQL’s `%`. The system performs the substitution automatically.

## Total Count

To retrieve the total number of records (useful for frontend pagination), add `count` to the query. The result will be returned in the `count` key of the JSON response.

`GET /api/users?limit=5&count`
