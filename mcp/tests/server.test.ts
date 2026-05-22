import { describe, it, expect } from "vitest";
import { TOOLS, TOOL_NAME_TO_CMD } from "../src/tools.js";

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
