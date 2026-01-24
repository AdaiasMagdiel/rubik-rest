/**
 * HTTP Client for the RubikREST API.
 */
class RubikClient {
    /**
     * @param {string} baseUrl The API base URL (e.g., "https://api.mysite.com/v1").
     * @param {object} headers Global headers (e.g., { "Authorization": "Bearer ..." }).
     */
    constructor(baseUrl, headers = {}) {
        this.baseUrl = baseUrl.replace(/\/+$/, "");
        this.headers = {
            "Content-Type": "application/json",
            "Accept": "application/json",
            ...headers,
        };
    }

    /**
     * Starts an operation on a specific resource.
     * @param {string} resource The resource slug (e.g., "users").
     * @returns {RubikResource}
     */
    from(resource) {
        return new RubikResource(this, resource);
    }
}

/**
 * Represents an API resource and exposes CRUD methods.
 */
class RubikResource {
    constructor(client, resourceName) {
        this.client = client;
        this.resourceName = resourceName;
        this.endpoint = `${client.baseUrl}/${resourceName}`;
        // Internal state for building queries (List only)
        this._queryParams = new URLSearchParams();
    }

    // =========================================================================
    // CRUD ACTIONS (Immediate Execution)
    // =========================================================================

    /**
     * Finds a single record by ID.
     * GET /resource/{id}
     * @param {string|number} id
     */
    async find(id) {
        return this._request("GET", `${this.endpoint}/${id}`);
    }

    /**
     * Creates a new record.
     * POST /resource
     * @param {object} data JSON object with data.
     */
    async create(data) {
        return this._request("POST", this.endpoint, data);
    }

    /**
     * Updates an existing record by ID.
     * PATCH /resource/{id}
     * @param {string|number} id
     * @param {object} data JSON object with data to update.
     */
    async update(id, data) {
        return this._request("PATCH", `${this.endpoint}/${id}`, data);
    }

    /**
     * Deletes a record by ID.
     * DELETE /resource/{id}
     * @param {string|number} id
     */
    async delete(id) {
        return this._request("DELETE", `${this.endpoint}/${id}`);
    }

    // =========================================================================
    // QUERY BUILDER (For Listings - GET /resource)
    // =========================================================================

    /**
     * Defines the columns to be returned.
     * @param {string|string[]} columns E.g., "id,name" or ["id", "name"].
     */
    select(columns) {
        const value = Array.isArray(columns) ? columns.join(",") : columns;
        this._queryParams.set("select", value);
        return this;
    }

    /**
     * Eager load relationships.
     * @param {string|string[]} relations E.g., "posts" or ["posts", "profile"].
     */
    with(relations) {
        const value = Array.isArray(relations) ? relations.join(",") : relations;
        this._queryParams.set("with", value);
        return this;
    }

    /**
     * Adds a WHERE filter.
     * @param {string} column Column name.
     * @param {string} operator Operator (eq, gt, like, etc).
     * @param {string|number|boolean} value Value.
     */
    where(column, operator, value) {
        // Maps to "column.operator=value" format expected by PHP Controller
        this._queryParams.append(`${column}.${operator}`, value);
        return this;
    }

    // Filter shortcuts
    eq(col, val) { return this.where(col, "eq", val); }
    neq(col, val) { return this.where(col, "neq", val); }
    gt(col, val) { return this.where(col, "gt", val); }
    gte(col, val) { return this.where(col, "gte", val); }
    lt(col, val) { return this.where(col, "lt", val); }
    lte(col, val) { return this.where(col, "lte", val); }
    like(col, val) { return this.where(col, "like", val); }
    ilike(col, val) { return this.where(col, "ilike", val); }
    is(col, val) { return this.where(col, "is", val); } // val: 'null', 'true', 'false'

    /**
     * IN filter.
     * @param {string} col 
     * @param {array} values 
     */
    in(col, values) {
        return this.where(col, "in", Array.isArray(values) ? values.join(",") : values);
    }

    /**
     * Ordering results.
     * @param {string} column 
     * @param {string} direction 'asc' or 'desc'
     */
    orderBy(column, direction = "asc") {
        this._queryParams.append("order", `${column}.${direction}`);
        return this;
    }

    /**
     * Sets the limit of records.
     * @param {number} limit 
     */
    limit(limit) {
        this._queryParams.set("limit", limit);
        return this;
    }

    /**
     * Sets the offset.
     * @param {number} offset 
     */
    offset(offset) {
        this._queryParams.set("offset", offset);
        return this;
    }

    /**
     * Simplified pagination helper.
     * @param {number} page Page number (starting at 1).
     * @param {number} pageSize Records per page.
     */
    page(page, pageSize = 20) {
        const offset = (page - 1) * pageSize;
        this.limit(pageSize);
        this.offset(offset);
        return this;
    }

    /**
     * Requests total record count in metadata.
     */
    withCount() {
        this._queryParams.set("count", "true");
        return this;
    }

    /**
     * Executes the listing query (GET).
     * @returns {Promise<{data: any[], count: number|null, error: any, status: number}>}
     */
    async get() {
        const qs = this._queryParams.toString();
        const url = `${this.endpoint}${qs ? "?" + qs : ""}`;
        return this._request("GET", url);
    }

    // =========================================================================
    // INTERNAL
    // =========================================================================

    /**
     * Internal method to perform fetch.
     */
    async _request(method, url, body = null) {
        const options = {
            method,
            headers: this.client.headers
        };

        if (body) {
            options.body = JSON.stringify(body);
        }

        try {
            const response = await fetch(url, options);
            const isJson = response.headers.get("content-type")?.includes("application/json");

            let payload = null;
            if (isJson) {
                payload = await response.json();
            }

            // Normalize response
            const result = {
                data: payload?.data ?? null,
                count: payload?.count ?? null,
                error: payload?.error ?? null,
                status: response.status
            };

            if (!response.ok && !result.error) {
                // Fallback for HTTP errors without standard JSON body
                result.error = { message: response.statusText, code: response.status };
            }

            return result;
        } catch (err) {
            // Network/Fetch errors
            return {
                data: null,
                count: null,
                error: err.message,
                status: 0
            };
        }
    }
}

// Export for CommonJS or ES Modules environments
if (typeof module !== "undefined" && module.exports) {
    module.exports = { RubikClient };
} else {
    window.RubikClient = RubikClient;
}