#!/usr/bin/env node
import { Server } from "@modelcontextprotocol/sdk/server/index.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import {
  CallToolRequestSchema,
  ListToolsRequestSchema,
} from "@modelcontextprotocol/sdk/types.js";
import { createRequire } from "node:module";
import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";
import { PhpBridge } from "./php-bridge.js";
import { TOOLS, TOOL_NAME_TO_CMD } from "./tools.js";

// Resolve bundled assets via require.resolve so the package works from
// npx, npm link, global installs, and the Docker image alike.
const req = createRequire(import.meta.url);
const HERE = dirname(fileURLToPath(import.meta.url));

function resolveBundledPath(npmName: string, fallbackRelativeToDist: string): string {
  try {
    return req.resolve(npmName);
  } catch {
    return resolve(HERE, fallbackRelativeToDist);
  }
}

const CLI_PATH = resolveBundledPath("@cybersafe-lab/saqr-mcp/../bin/saqr-cli", "../../bin/saqr-cli");
const CORPUS_DEFAULT_PATH = resolveBundledPath("@cybersafe-lab/saqr-mcp/../corpus/frameworks.json", "../../corpus/frameworks.json");

const bridge = new PhpBridge({ cliPath: CLI_PATH, corpusDefaultPath: CORPUS_DEFAULT_PATH });

const server = new Server(
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
