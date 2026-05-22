import { describe, it, expect } from "vitest";
import { existsSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";

const HERE = dirname(fileURLToPath(import.meta.url));

describe("bundled assets resolvable from package root", () => {
  it("bin/saqr-cli exists", () => {
    expect(existsSync(resolve(HERE, "../../bin/saqr-cli"))).toBe(true);
  });
  it("corpus/frameworks.json exists", () => {
    expect(existsSync(resolve(HERE, "../../corpus/frameworks.json"))).toBe(true);
  });
});
