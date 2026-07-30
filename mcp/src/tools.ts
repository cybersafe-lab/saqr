import type { Tool } from "@modelcontextprotocol/sdk/types.js";

export const TOOLS: Tool[] = [
  {
    name: "saqr_search",
    description: "Search Saqr's Saudi cybersecurity / data-protection corpus by free-text question. Returns top-3 entries with official-source references.",
    inputSchema: {
      type: "object",
      properties: { question: { type: "string", description: "Free-text question" } },
      required: ["question"],
    },
  },
  {
    name: "saqr_compare_frameworks",
    description: "Compare two Saudi cybersecurity frameworks (NCA ECC, SAMA CSF, CST CRF, Aramco SACS-210, PDPL, ISO 27001) using a curated crosswalk. Returns the official-source references behind the answer it gives.",
    inputSchema: {
      type: "object",
      properties: {
        framework_a: { type: "string" },
        framework_b: { type: "string" },
      },
      required: ["framework_a", "framework_b"],
    },
  },
  {
    name: "saqr_explain_control",
    description: "Look up the corpus entry covering a control reference (e.g. ECC-2-3-1, SAMA CSF 3.3.5). Returns a practitioner explanation of the domain that control belongs to, with official-source references — not the verbatim control text.",
    inputSchema: {
      type: "object",
      properties: { control_ref: { type: "string" } },
      required: ["control_ref"],
    },
  },
  {
    name: "saqr_show_corpus",
    description: "List the frameworks Saqr covers. Useful for discovering what's available before asking.",
    inputSchema: { type: "object", properties: {}, required: [] },
  },
];

export const TOOL_NAME_TO_CMD: Record<string, string> = {
  saqr_search: "search",
  saqr_compare_frameworks: "compare",
  saqr_explain_control: "explain_control",
  saqr_show_corpus: "show_corpus",
};
