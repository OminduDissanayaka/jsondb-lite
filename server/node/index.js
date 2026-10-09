const express = require("express");
const JSONDBLite = require("../../lib/node");

const app = express();
app.disable("x-powered-by");
app.use(express.json({ limit: process.env.JSONDB_MAX_BODY || "1mb" }));

const db = new JSONDBLite();
const PORT = Number(process.env.PORT || 5425);
const HOST = process.env.HOST || "0.0.0.0";

function errorResponse(res, error) {
  const status = error.status || (error instanceof TypeError || error instanceof SyntaxError ? 400 : 500);
  return res.status(status).json({
    success: false,
    error: status >= 500 && status === 500 && !error.status ? "Internal server error." : error.message
  });
}

app.get("/", (_req, res) => {
  res.json({
    name: "JSONDB Lite",
    version: "1.2.0",
    status: "ok",
    endpoints: ["POST /api", "GET /health"]
  });
});

app.get("/health", async (_req, res) => {
  try {
    res.json({ success: true, ...(await db.health()) });
  } catch (error) {
    errorResponse(res, error);
  }
});

app.post("/api", async (req, res) => {
  try {
    const { action, collection, data, query, limit, offset } = req.body || {};
    if (action === "health") return res.json({ success: true, ...(await db.health()) });
    if (!["insert", "search", "update", "delete"].includes(action)) {
      return res.status(400).json({ success: false, error: "Invalid action. Use insert, search, update, delete, or health." });
    }
    if (typeof collection !== "string") {
      return res.status(400).json({ success: false, error: "A valid collection name is required." });
    }

    if (action === "insert") {
      const result = await db.insert(collection, data);
      return res.status(201).json({ success: true, ...result });
    }
    if (action === "search") {
      const result = await db.search(collection, query || {}, { limit, offset });
      return res.json({ success: true, ...result });
    }
    if (action === "update") {
      const result = await db.update(collection, query, data);
      return res.json({ success: true, ...result });
    }
    const result = await db.delete(collection, query);
    return res.json({ success: true, ...result });
  } catch (error) {
    errorResponse(res, error);
  }
});

app.use((error, _req, res, _next) => {
  if (error && error.type === "entity.parse.failed") {
    return res.status(400).json({ success: false, error: "Malformed JSON request body." });
  }
  console.error("[JSONDB Lite server]", error);
  return errorResponse(res, error);
});

app.listen(PORT, HOST, () => {
  console.log(`JSONDB Lite API server listening on http://${HOST}:${PORT}`);
});
