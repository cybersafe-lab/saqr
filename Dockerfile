# Stage 1: build TS
# node:20-alpine digest pinned 2026-05-22 (SEC-003). Re-resolve on bump:
#   curl-based resolver in RELEASE_CHECKLIST.md
FROM node:20-alpine@sha256:fb4cd12c85ee03686f6af5362a0b0d56d50c58a04632e6c0fb8363f609372293 AS ts-builder
WORKDIR /build
COPY mcp/package.json mcp/package-lock.json* ./mcp/
RUN cd mcp && npm ci --omit=dev
COPY mcp/ ./mcp/
COPY corpus/ ./corpus/
COPY bin/ ./bin/
COPY src/ ./src/
RUN cd mcp && npm install typescript && npm run build

# Stage 2: runtime — PHP-CLI alpine + node binary
# php:8.3-cli-alpine digest pinned 2026-05-22 (SEC-003).
FROM php:8.3-cli-alpine@sha256:2e6b46e0c087cc1c17cfd91172112d8d9db5cbf9503b20e3418c10bd198748b5 AS runtime

# Copy node binary from the build stage (avoids the full node:alpine image).
COPY --from=ts-builder /usr/local/bin/node /usr/local/bin/node

# Non-root user (uid 65532 = "nobody" convention)
RUN adduser -D -u 65532 saqr
WORKDIR /app
COPY --from=ts-builder /build/mcp/dist/ /app/dist/
COPY --from=ts-builder /build/mcp/node_modules/ /app/node_modules/
COPY --from=ts-builder /build/corpus/ /app/corpus/
COPY --from=ts-builder /build/bin/ /app/bin/
COPY --from=ts-builder /build/src/ /app/src/

USER saqr
ENTRYPOINT ["node", "/app/dist/server.js"]
