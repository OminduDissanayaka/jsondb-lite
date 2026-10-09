module.exports = {
  apps: [{
    name: "jsondb-lite",
    script: "./server/node/index.js",
    instances: 1,
    exec_mode: "fork",
    autorestart: true,
    watch: false,
    max_memory_restart: "250M",
    env: {
      NODE_ENV: "production",
      HOST: "0.0.0.0",
      PORT: "5425"
    }
  }]
};
