# JSONDB Lite API

## Architecture

- `lib/node/index.js`: database engine class (`JSONDBLite`); no HTTP server.
- `lib/php/index.php`: pure PHP database engine class (`JSONDBLite`); no HTTP response/routing code.
- `server/node/index.js`: Express routes and request/response handling.
- `server/php/index.php`: PHP HTTP/API layer, which requires the pure PHP engine.
- `data/db.json`: automatically created JSON storage file.

## Endpoints

| Runtime | Endpoint | Method |
|---|---|---|
| Node.js | `http://localhost:5425/api` | POST |
| Node.js | `http://localhost:5425/health` | GET |
| PHP | Deployed `server/php/index.php` URL | POST |
| PHP | Same PHP URL | GET health check |

Set `PORT` to change the Node.js server port. `JSONDB_FILE` overrides the Node.js storage path. Node.js JSON request limit defaults to `1mb` and can be changed using `JSONDB_MAX_BODY`.

All API requests use `Content-Type: application/json`.

## Health

```json
{"action":"health"}
```

## Insert one document

```json
{
  "action": "insert",
  "collection": "users",
  "data": {"name": "Omindu", "age": 19}
}
```

## Insert multiple documents

```json
{
  "action": "insert",
  "collection": "users",
  "data": [
    {"name": "Nimali", "age": 21},
    {"name": "Kamal", "age": 25}
  ]
}
```

The engine creates `_id` and `_createdAt` fields if absent.

## Search

```json
{"action":"search","collection":"users","query":{"name":"Omindu"}}
```

An empty query `{}` matches all documents. Search supports `limit` (default 100, max 1000) and `offset`.

Supported operators:

```json
{"age":{"$gt":18}}
{"age":{"$gte":18,"$lt":30}}
{"name":{"$contains":"min"}}
{"status":{"$ne":"blocked"}}
{"role":{"$in":["admin","editor"]}}
```

Operators: `$contains`, `$gt`, `$gte`, `$lt`, `$lte`, `$ne`, `$in`. Nested object paths such as `profile.city` are supported.

## Update

A non-empty query is required to avoid accidental bulk changes.

```json
{
  "action": "update",
  "collection": "users",
  "query": {"_id": "DOCUMENT_ID"},
  "data": {"age": 20}
}
```

Returns `updatedCount`. `_id` and `_createdAt` are preserved; `_updatedAt` is added.

## Delete

A non-empty query is required.

```json
{
  "action": "delete",
  "collection": "users",
  "query": {"_id": "DOCUMENT_ID"}
}
```

Returns `deletedCount`.

## cURL example

```bash
curl -X POST http://localhost:5425/api \
  -H 'Content-Type: application/json' \
  -d '{"action":"search","collection":"users","query":{}}'
```

## Responses and status codes

- `200`: search, update, delete, or health succeeded
- `201`: insert succeeded
- `400`: malformed request or invalid input
- `405`: unsupported HTTP method (PHP server)
- `500`: storage or server error

Responses use JSON, with `success: true` on success and `success: false` on errors.

## Deployment and safety

Use HTTPS and add authentication/authorization before exposing the API publicly. Protect `data/db.json` from direct web access and back it up regularly. JSON-file storage is suitable for small projects, not high-concurrency workloads.
