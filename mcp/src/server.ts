#!/usr/bin/env node
import { Server } from "@modelcontextprotocol/sdk/server/index.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import {
  CallToolRequestSchema,
  ListToolsRequestSchema,
} from "@modelcontextprotocol/sdk/types.js";
import { createRequire } from "node:module";
import { existsSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";
import { PhpBridge } from "./php-bridge.js";
import { TOOLS, TOOL_NAME_TO_CMD } from "./tools.js";

// Resolve bundled assets with a three-tier fallback:
//   1. require.resolve — works when the package is installed via npm/npx
//      (vendor-bundled/ is published inside the package root).
//   2. Filesystem check relative to dist/ — works after `npm run prepack` in
//      the source tree (vendor-bundled/ is a sibling of dist/).
//   3. Dev tree fallback — works during development where dist/ lives inside
//      mcp/ and the assets sit at the repo root (two levels up).
const req = createRequire(import.meta.url);
const HERE = dirname(fileURLToPath(import.meta.url));

function resolveAsset(npmVendorPath: string, distRelative: string, devRelative: string): string {
  try {
    return req.resolve(npmVendorPath);
  } catch { /* not reachable via require.resolve — try filesystem fallbacks */ }
  const viaVendorBundled = resolve(HERE, distRelative);
  if (existsSync(viaVendorBundled)) return viaVendorBundled;
  return resolve(HERE, devRelative);
}

// Published package: vendor-bundled/{bin,corpus} live inside the npm package.
// Dev tree (mcp/dist/server.js → mcp/vendor-bundled/ → repo/{bin,corpus}).
const CLI_PATH = resolveAsset(
  "@cybersafe-lab/saqr-mcp/vendor-bundled/bin/saqr-cli",
  "../vendor-bundled/bin/saqr-cli",
  "../../bin/saqr-cli",
);
const CORPUS_DEFAULT_PATH = resolveAsset(
  "@cybersafe-lab/saqr-mcp/vendor-bundled/corpus/frameworks.json",
  "../vendor-bundled/corpus/frameworks.json",
  "../../corpus/frameworks.json",
);

const bridge = new PhpBridge({ cliPath: CLI_PATH, corpusDefaultPath: CORPUS_DEFAULT_PATH });

export const server = new Server(
  { name: "saqr-mcp", version: "0.1.0" },
  { capabilities: { tools: {} } },
);

server.setRequestHandler(ListToolsRequestSchema, async () => ({ tools: TOOLS }));

server.setRequestHandler(CallToolRequestSchema, async (req) => {
  const cmd = TOOL_NAME_TO_CMD[req.params.name];
  if (!cmd) {
    return {
      isError: true,
      content: [{ type: "text", text: `unknown tool: ${req.params.name}` }],
    };
  }
  try {
    const result = await bridge.call(cmd, (req.params.arguments ?? {}) as Record<string, unknown>);
    return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
  } catch (e) {
    const msg = e instanceof Error ? e.message : String(e);
    return { isError: true, content: [{ type: "text", text: msg }] };
  }
});

process.on("SIGTERM", () => { bridge.shutdown(); process.exit(0); });
process.on("SIGINT", () => { bridge.shutdown(); process.exit(0); });

const transport = new StdioServerTransport();
await server.connect(transport);
