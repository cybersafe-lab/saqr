import { describe, it, expect } from "vitest";
import { existsSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";

const HERE = dirname(fileURLToPath(import.meta.url));

// The repo dev-tree layout: tests/ → mcp/tests/, assets at repo root.
const REPO_ROOT = resolve(HERE, "../..");

describe("bundled assets resolvable from repo dev tree", () => {
  it("bin/saqr-cli exists at repo root", () => {
    expect(existsSync(resolve(REPO_ROOT, "bin/saqr-cli"))).toBe(true);
  });
  it("corpus/frameworks.json exists at repo root", () => {
    expect(existsSync(resolve(REPO_ROOT, "corpus/frameworks.json"))).toBe(true);
  });
});

describe("vendor-bundled layout (post-prepack)", () => {
  const VENDOR = resolve(HERE, "../vendor-bundled");
  const vendorExists = existsSync(VENDOR);

  it("vendor-bundled/bin/saqr-cli exists when prepack has run", () => {
    if (!vendorExists) {
      // prepack not yet run — skip rather than fail in CI pre-build step
      console.log("vendor-bundled/ not present (prepack not run) — skipping");
      return;
    }
    expect(existsSync(resolve(VENDOR, "bin/saqr-cli"))).toBe(true);
  });

  it("vendor-bundled/corpus/frameworks.json exists when prepack has run", () => {
    if (!vendorExists) {
      console.log("vendor-bundled/ not present (prepack not run) — skipping");
      return;
    }
    expect(existsSync(resolve(VENDOR, "corpus/frameworks.json"))).toBe(true);
  });

  it("vendor-bundled/src/Retriever.php exists when prepack has run", () => {
    if (!vendorExists) {
      console.log("vendor-bundled/ not present (prepack not run) — skipping");
      return;
    }
    expect(existsSync(resolve(VENDOR, "src/Retriever.php"))).toBe(true);
  });
});
