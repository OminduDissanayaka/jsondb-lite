const fs = require("node:fs/promises");
const path = require("node:path");
const crypto = require("node:crypto");

/**
 * JSONDB Lite database engine.
 * This module contains storage and CRUD/query logic only.
 * It does not create an HTTP server and has no Express dependency.
 */
class JSONDBLite {
  constructor(options = {}) {
    this.file = path.resolve(
      options.file || process.env.JSONDB_FILE || path.join(__dirname, "../../data/db.json")
    );
    this.writeQueue = Promise.resolve();
  }

  async ensure() {
    await fs.mkdir(path.dirname(this.file), { recursive: true });
    try {
      await fs.access(this.file);
    } catch {
      await fs.writeFile(this.file, JSON.stringify({ collections: {} }, null, 2), "utf8");
    }
  }

  async read() {
    await this.ensure();
    try {
      const raw = await fs.readFile(this.file, "utf8");
      const db = JSON.parse(raw || '{"collections":{}}');
      if (!db.collections || typeof db.collections !== "object" || Array.isArray(db.collections)) {
        db.collections = {};
      }
      return db;
    } catch (error) {
      if (error instanceof SyntaxError) {
        const dbError = new Error("Database JSON is invalid. Back up and repair data/db.json.");
        dbError.status = 500;
        throw dbError;
      }
      throw error;
    }
  }

  async write(db) {
    const operation = this.writeQueue.then(async () => {
      await this.ensure();
      const tempFile = `${this.file}.${process.pid}.tmp`;
      await fs.writeFile(tempFile, JSON.stringify(db, null, 2), "utf8");
      await fs.rename(tempFile, this.file);
    });
    this.writeQueue = operation.catch(() => {});
    return operation;
  }

  validateCollection(name) {
    if (typeof name !== "string" || !/^[A-Za-z0-9_-]{1,80}$/.test(name)) {
      const error = new Error("Invalid collection. Use 1-80 letters, numbers, underscores, or hyphens.");
      error.status = 400;
      throw error;
    }
  }

  collection(db, name) {
    if (!Array.isArray(db.collections[name])) db.collections[name] = [];
    return db.collections[name];
  }

  valueAtPath(document, dottedPath) {
    return String(dottedPath).split(".").reduce(
      (value, key) => value == null ? undefined : value[key],
      document
    );
  }

  operatorMatch(actual, expected) {
    if (expected && typeof expected === "object" && !Array.isArray(expected)) {
      return Object.entries(expected).every(([operator, value]) => {
        switch (operator) {
          case "$contains": return typeof actual === "string" && actual.includes(String(value));
          case "$gt": return actual > value;
          case "$gte": return actual >= value;
          case "$lt": return actual < value;
          case "$lte": return actual <= value;
          case "$ne": return actual !== value;
          case "$in": return Array.isArray(value) && value.includes(actual);
          default: return false;
        }
      });
    }
    return actual === expected;
  }

  matches(document, query = {}) {
    if (!query || typeof query !== "object" || Array.isArray(query)) return false;
    return Object.entries(query).every(([key, expected]) =>
      this.operatorMatch(this.valueAtPath(document, key), expected)
    );
  }

  async insert(collection, data) {
    this.validateCollection(collection);
    const rows = Array.isArray(data) ? data : [data];
    if (!rows.length || rows.some(row => !row || typeof row !== "object" || Array.isArray(row))) {
      const error = new Error("Insert data must be a JSON object or an array of JSON objects.");
      error.status = 400;
      throw error;
    }
    const db = await this.read();
    const documents = this.collection(db, collection);
    const inserted = rows.map(row => ({
      ...row,
      _id: row._id || crypto.randomUUID(),
      _createdAt: row._createdAt || new Date().toISOString()
    }));
    documents.push(...inserted);
    await this.write(db);
    return { insertedCount: inserted.length, data: inserted };
  }

  async search(collection, query = {}, options = {}) {
    this.validateCollection(collection);
    const db = await this.read();
    const documents = this.collection(db, collection);
    const filtered = documents.filter(document => this.matches(document, query));
    const offset = Math.max(0, Number.isFinite(Number(options.offset)) ? Math.floor(Number(options.offset)) : 0);
    const limit = Math.min(1000, Math.max(0, Number.isFinite(Number(options.limit)) ? Math.floor(Number(options.limit)) : 100));
    return { count: filtered.length, data: filtered.slice(offset, offset + limit) };
  }

  async update(collection, query, data) {
    this.validateCollection(collection);
    if (!query || typeof query !== "object" || Array.isArray(query) || Object.keys(query).length === 0) {
      const error = new Error("Update requires a non-empty query object.");
      error.status = 400;
      throw error;
    }
    if (!data || typeof data !== "object" || Array.isArray(data)) {
      const error = new Error("Update data must be a JSON object.");
      error.status = 400;
      throw error;
    }
    const patch = { ...data };
    delete patch._id;
    delete patch._createdAt;
    const db = await this.read();
    const documents = this.collection(db, collection);
    let updatedCount = 0;
    db.collections[collection] = documents.map(document => {
      if (!this.matches(document, query)) return document;
      updatedCount++;
      return {
        ...document,
        ...patch,
        _id: document._id,
        _createdAt: document._createdAt,
        _updatedAt: new Date().toISOString()
      };
    });
    await this.write(db);
    return { updatedCount };
  }

  async delete(collection, query) {
    this.validateCollection(collection);
    if (!query || typeof query !== "object" || Array.isArray(query) || Object.keys(query).length === 0) {
      const error = new Error("Delete requires a non-empty query object.");
      error.status = 400;
      throw error;
    }
    const db = await this.read();
    const documents = this.collection(db, collection);
    const remaining = documents.filter(document => !this.matches(document, query));
    const deletedCount = documents.length - remaining.length;
    db.collections[collection] = remaining;
    await this.write(db);
    return { deletedCount };
  }

  async health() {
    await this.ensure();
    return { status: "ok" };
  }
}

module.exports = JSONDBLite;
