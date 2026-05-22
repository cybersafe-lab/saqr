import { describe, it, expect, afterEach } from "vitest";
import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";
import { PhpBridge } from "../src/php-bridge.js";

const __dirname = dirname(fileURLToPath(import.meta.url));

const CLI = resolve(__dirname, "../../bin/saqr-cli");
const CORPUS = resolve(__dirname, "../../corpus/frameworks.json");

describe("PhpBridge", () => {
  let bridge: PhpBridge | null = null;
  afterEach(() => { bridge?.shutdown(); bridge = null; });

  it("show_corpus returns entry_count", async () => {
    bridge = new PhpBridge({ cliPath: CLI, corpusDefaultPath: CORPUS });
    const r = await bridge.call("show_corpus", {}) as { entry_count: number };
    expect(r.entry_count).toBeGreaterThan(0);
  });

  it("multiple concurrent requests route by id", async () => {
    bridge = new PhpBridge({ cliPath: CLI, corpusDefaultPath: CORPUS });
    const [a, b] = await Promise.all([
      bridge.call("show_corpus", {}),
      bridge.call("search", { question: "PDPL" }),
    ]) as [{ entry_count: number }, { results: unknown[] }];
    expect(a.entry_count).toBeGreaterThan(0);
    expect(b.results).toBeInstanceOf(Array);
  });

  it("rejects question longer than 1000 chars", async () => {
    bridge = new PhpBridge({ cliPath: CLI, corpusDefaultPath: CORPUS });
    await expect(bridge.call("search", { question: "a".repeat(1001) }))
      .rejects.toThrow(/BAD_REQUEST/);
  });

  it("rejects question containing NUL byte", async () => {
    bridge = new PhpBridge({ cliPath: CLI, corpusDefaultPath: CORPUS });
    await expect(bridge.call("search", { question: "PDPL\0" }))
      .rejects.toThrow(/control characters/);
  });
});
