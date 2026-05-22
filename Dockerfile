# TODO(release): pin base image by digest before tagging.
# See RELEASE_CHECKLIST.md — run:
#   docker pull node:20-alpine && docker inspect node:20-alpine --format '{{index .RepoDigests 0}}'
#   docker pull php:8.3-cli-alpine && docker inspect php:8.3-cli-alpine --format '{{index .RepoDigests 0}}'
# then replace the tags below with node:20-alpine@sha256:<digest> etc.
# Stage 1: build TS
FROM node:20-alpine AS ts-builder
WORKDIR /build
COPY mcp/package.json mcp/package-lock.json* ./mcp/
RUN cd mcp && npm ci --omit=dev
COPY mcp/ ./mcp/
COPY corpus/ ./corpus/
COPY bin/ ./bin/
COPY src/ ./src/
RUN cd mcp && npm install typescript && npm run build

# Stage 2: runtime — PHP-CLI alpine + node binary
# TODO(release): pin with @sha256:<digest> per RELEASE_CHECKLIST.md
FROM php:8.3-cli-alpine AS runtime

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
