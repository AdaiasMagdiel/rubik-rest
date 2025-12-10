class RubikREST {
    constructor(baseUrl, headers = {}) {
        this.baseUrl = baseUrl.replace(/\/+$/, "");
        this.headers = {
            "Content-Type": "application/json",
            ...headers,
        };
    }

    from(table) {
        return new RubikRESTQuery(this.baseUrl, table, this.headers);
    }
}

class RubikRESTQuery {
    constructor(baseUrl, table, headers) {
        this.baseUrl = baseUrl;
        this.table = table;
        this.headers = headers;

        this._select = null;
        this._filters = [];
        this._order = [];
        this._limit = null;
        this._offset = null;
        this._count = false;

        this._body = null;
        this._method = "GET";
    }

    // ------------------------------------------------------------
    // SELECT
    // ------------------------------------------------------------
    select(columns = "*") {
        this._select = columns;
        return this;
    }

    // ------------------------------------------------------------
    // INSERT
    // ------------------------------------------------------------
    insert(values) {
        this._method = "POST";
        this._body = values;
        return this;
    }

    // ------------------------------------------------------------
    // WHERE-FILTERS
    // ------------------------------------------------------------
    where(key, op, value) {
        this._filters.push({ key, op, value });
        return this;
    }

    // .eq("id", 10)
    eq(key, value) { return this.where(key, "eq", value); }
    neq(key, value) { return this.where(key, "neq", value); }
    gt(key, value) { return this.where(key, "gt", value); }
    gte(key, value) { return this.where(key, "gte", value); }
    lt(key, value) { return this.where(key, "lt", value); }
    lte(key, value) { return this.where(key, "lte", value); }

    like(key, value) { return this.where(key, "like", value); }
    ilike(key, value) { return this.where(key, "ilike", value); }

    in(key, array) { return this.where(key, "in", array.join(",")); }
    notIn(key, array) { return this.where(key, "not.in", array.join(",")); }

    // ------------------------------------------------------------
    // ORDER
    // ------------------------------------------------------------
    order(column, direction = "asc") {
        this._order.push({ column, direction });
        return this;
    }

    // ------------------------------------------------------------
    // LIMIT/OFFSET
    // ------------------------------------------------------------
    limit(n) {
        this._limit = n;
        return this;
    }

    offset(n) {
        this._offset = n;
        return this;
    }

    // ------------------------------------------------------------
    // COUNT
    // ------------------------------------------------------------
    count() {
        this._count = true;
        return this;
    }

    // ------------------------------------------------------------
    // BUILD URL + QUERY
    // ------------------------------------------------------------
    _buildUrl() {
        const params = new URLSearchParams();

        if (this._select) {
            params.set("select", this._select);
        }

        for (const f of this._filters) {
            const key = `${f.key}.${f.op}`;
            params.set(key, f.value);
        }

        for (const o of this._order) {
            params.append("order", `${o.column}.${o.direction}`);
        }

        if (this._limit !== null) params.set("limit", this._limit);
        if (this._offset !== null) params.set("offset", this._offset);

        if (this._count) params.set("count", "");

        const qs = params.toString();
        return `${this.baseUrl}/${this.table}${qs ? "?" + qs : ""}`;
    }

    // ------------------------------------------------------------
    // EXECUTE
    // ------------------------------------------------------------
    async _exec() {
        const url = this._buildUrl();

        const options = {
            method: this._method,
            headers: this.headers
        };

        if (this._body !== null) {
            options.body = JSON.stringify(this._body);
        }

        const response = await fetch(url, options);
        const json = await response.json();

        return {
            status: response.status,
            error: json.error || null,
            data: json.data ?? null,
            count: json.count ?? null
        };
    }

    // ------------------------------------------------------------
    // CLIENT-FACING METHODS
    // ------------------------------------------------------------
    async all() {
        return await this._exec();
    }

    async single() {
        const result = await this._exec();
        if (result.error) return result;

        if (!result.data || result.data.length !== 1) {
            return {
                ...result,
                error: "Single record expected, got " + (result.data ? result.data.length : 0)
            };
        }

        result.data = result.data[0];
        return result;
    }

    async maybeSingle() {
        const result = await this._exec();
        if (result.error) return result;

        if (!result.data || result.data.length === 0) {
            result.data = null;
            return result;
        }

        if (result.data.length > 1) {
            return {
                ...result,
                error: "MaybeSingle expected 0 or 1 row, got " + result.data.length
            };
        }

        result.data = result.data[0];
        return result;
    }
}

// export { RubikREST };
