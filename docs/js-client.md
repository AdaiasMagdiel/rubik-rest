# JavaScript Client

The package includes the `RubikREST.js` file, a lightweight wrapper around the Fetch API designed to simplify data consumption on the frontend.

## Installation

Copy the `RubikREST.js` file into your frontend project or import it if you are using a bundler.

```javascript
import { RubikClient } from "./path/to/RubikREST.js";
```

## Initialization

```javascript
const api = new RubikClient("https://mysite.com/api", {
  Authorization: "Bearer mytoken123",
});
```

## Consuming Resources

The client uses a fluent (chainable) pattern to build queries.

### Listing Data (GET)

```javascript
const response = await api
  .from("users")
  .select(["id", "name"])
  .with("posts")
  .where("age", "gte", 18)
  .orderBy("name", "asc")
  .page(1, 15) // Page 1, 15 items per page
  .withCount() // Requests the total record count
  .get();

if (response.data) {
  console.log(response.data); // Array of users
  console.log(response.count); // Total records
} else {
  console.error(response.error);
}
```

### CRUD Operations

#### Create (POST)

```javascript
const res = await api.from("users").create({
  name: "Adaías",
  email: "teste@teste.com",
});
```

#### Fetch One (GET by ID)

```javascript
const res = await api.from("users").find(1);
```

#### Update (PATCH)

```javascript
const res = await api.from("users").update(1, {
  name: "Adaías Magdiel",
});
```

#### Delete (DELETE)

```javascript
await api.from("users").delete(1);
```

### Filter Helper Methods

The JS client provides shortcuts for all API operators:

```javascript
api
  .from("products")
  .eq("category", "electronics")
  .gt("price", 100)
  .like("name", "Smart*")
  .in("status", ["instock", "preorder"])
  .get();
```
