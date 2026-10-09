# JSONDB Lite

A modular JSON-file database with separate database-engine and API-server layers.

## Architecture

```text
JSONDB-Lite/
├── lib/
│   ├── node/
│   │   └── index.js       # Node.js database engine only
│   └── php/
│       └── index.php     # Pure PHP database engine only
├── server/
│   ├── node/
│   │   └── index.js      # Express API server
│   └── php/
│       └── index.php     # Pure PHP HTTP/API layer
├── data/
│   └── .gitkeep          # db.json is created automatically
├── docs/
│   └── API.md
├── package.json
├── ecosystem.config.cjs
└── README.md
```

**Design rule:** `lib/node` and `lib/php` contain database engine logic only. HTTP server code, routes, request parsing, and response handling live under `server/`.

## Node.js

Requires Node.js 18+.

```bash
npm install
npm start
```

The Express API listens on `http://localhost:5425` by default. Change the port with the `PORT` environment variable.

### PM2

```bash
npx pm2 start ecosystem.config.cjs
npx pm2 save
npx pm2 status
npx pm2 logs jsondb-lite
```

## PHP shared hosting

Upload the project while preserving the folder structure, then publish `server/php/index.php` through your web server. PHP 8.1+ is recommended. The PHP engine is pure PHP and does not require Composer or third-party packages. PHP uses the hosting provider's regular HTTP/HTTPS server; it does not open port 5425 itself.

Ensure the `data/` directory is writable by the PHP process and protected from direct public access.

## API

Send JSON requests to `POST /api` on Node.js, or the deployed `server/php/index.php` URL.

```json
{"action":"insert","collection":"users","data":{"name":"Omindu","age":19}}
{"action":"search","collection":"users","query":{"name":"Omindu"}}
{"action":"update","collection":"users","query":{"_id":"DOCUMENT_ID"},"data":{"age":20}}
{"action":"delete","collection":"users","query":{"_id":"DOCUMENT_ID"}}
{"action":"health"}
```

Full endpoint examples and query operators are in [`docs/API.md`](docs/API.md).

## Security and limitations

Use HTTPS and authentication before exposing the API publicly. Do not make `data/db.json` directly downloadable. JSON-file storage is intended for small projects and low write volume, not high-concurrency workloads.
