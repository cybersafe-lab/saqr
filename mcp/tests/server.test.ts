import { describe, it, expect } from "vitest";
import { Client } from "@modelcontextprotocol/sdk/client/index.js";
import { InMemoryTransport } from "@modelcontextprotocol/sdk/inMemory.js";
import { Server } from "@modelcontextprotocol/sdk/server/index.js";
import {
  CallToolRequestSchema,
  ListToolsRequestSchema,
} from "@modelcontextprotocol/sdk/types.js";
import { TOOLS, TOOL_NAME_TO_CMD } from "../src/tools.js";

// ---------------------------------------------------------------------------
// Static tool-table tests (original)
// ---------------------------------------------------------------------------

describe("MCP tool surface", () => {
  it("declares exactly the 4 documented tools", () => {
    expect(TOOLS.map(t => t.name).sort()).toEqual([
      "saqr_compare_frameworks",
      "saqr_explain_control",
      "saqr_search",
      "saqr_show_corpus",
    ]);
  });

  it("every tool name maps to a CLI cmd", () => {
    for (const t of TOOLS) {
      expect(TOOL_NAME_TO_CMD[t.name]).toBeTypeOf("string");
    }
  });

  it("required fields are declared on schemas that need them", () => {
    expect(TOOLS.find(t => t.name === "saqr_search")!.inputSchema.required).toContain("question");
    expect(TOOLS.find(t => t.name === "saqr_compare_frameworks")!.inputSchema.required).toEqual(["framework_a", "framework_b"]);
  });
});

// ---------------------------------------------------------------------------
// MCP wire-format tests (I2)
//
// We build a minimal Server instance that mirrors the real server's handlers
// but stubs out PhpBridge entirely. This tests the MCP protocol layer
// (ListTools / CallTool routing) without spawning PHP.
// ---------------------------------------------------------------------------

function buildTestServer(): Server {
  const server = new Server(
    { name: "saqr-mcp-test", version: "0.1.0" },
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
    // Stub: return a synthetic success response.
    return { content: [{ type: "text", text: `stub:${cmd}` }] };
  });

  return server;
}

async function buildLinkedClient(): Promise<{ client: Client; cleanup: () => Promise<void> }> {
  const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair();
  const server = buildTestServer();
  const client = new Client(
    { name: "test-client", version: "0.0.1" },
    { capabilities: {} },
  );
  await server.connect(serverTransport);
  await client.connect(clientTransport);
  return {
    client,
    cleanup: async () => {
      await client.close();
      await server.close();
    },
  };
}

describe("MCP wire format — tools/list", () => {
  it("returns all 4 tools via InMemoryTransport", async () => {
    const { client, cleanup } = await buildLinkedClient();
    try {
      const result = await client.listTools();
      expect(result.tools.map((t: { name: string }) => t.name).sort()).toEqual([
        "saqr_compare_frameworks",
        "saqr_explain_control",
        "saqr_search",
        "saqr_show_corpus",
      ]);
    } finally {
      await cleanup();
    }
  });
});

describe("MCP wire format — tools/call", () => {
  it("calls a known tool and gets a text response", async () => {
    const { client, cleanup } = await buildLinkedClient();
    try {
      const result = await client.callTool({ name: "saqr_show_corpus", arguments: {} });
      expect(result.isError).toBeFalsy();
      expect((result.content as Array<{ type: string; text: string }>)[0].type).toBe("text");
    } finally {
      await cleanup();
    }
  });

  it("returns isError:true for an unknown tool name", async () => {
    const { client, cleanup } = await buildLinkedClient();
    try {
      const result = await client.callTool({ name: "saqr_nonexistent_tool", arguments: {} });
      expect(result.isError).toBe(true);
      const text = (result.content as Array<{ type: string; text: string }>)[0].text;
      expect(text).toMatch(/unknown tool/);
    } finally {
      await cleanup();
    }
  });
});
