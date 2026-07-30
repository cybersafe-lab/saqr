import { describe, it, expect, afterEach, vi, beforeEach } from "vitest";
import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";

const __dirname = dirname(fileURLToPath(import.meta.url));

const CLI = resolve(__dirname, "../../bin/saqr-cli");
const CORPUS = resolve(__dirname, "../../corpus/frameworks.json");

// SEC-001: capture spawn calls to assert shell:false + array args.
// The mock must be declared at module scope before any import of PhpBridge,
// so we use vi.mock hoisting.
const spawnCalls: Parameters<typeof import("node:child_process").spawn>[] = [];

vi.mock("node:child_process", async () => {
  const actual = await vi.importActual<typeof import("node:child_process")>("node:child_process");
  return {
    ...actual,
    spawn: (...args: Parameters<typeof actual.spawn>) => {
      spawnCalls.push(args);
      return actual.spawn(...args);
    },
  };
});

// Import after mock is registered.
const { PhpBridge } = await import("../src/php-bridge.js");

describe("PhpBridge", () => {
  let bridge: InstanceType<typeof PhpBridge> | null = null;
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

  // The tool descriptions promise official-source references, so the result
  // shape has to carry them. 'score' was declared once and always null.
  it("search results carry refs and no score", async () => {
    bridge = new PhpBridge({ cliPath: CLI, corpusDefaultPath: CORPUS });
    const r = await bridge.call("search", { question: "What is the NCA ECC?" }) as {
      results: Array<Record<string, unknown>>;
    };
    expect(r.results.length).toBeGreaterThan(0);
    const first = r.results[0];
    expect(Object.keys(first).sort()).toEqual(["content", "framework", "id", "refs", "title"]);
    const refs = first.refs as Array<{ title: string; url: string }>;
    expect(refs.length).toBeGreaterThan(0);
    expect(typeof refs[0].title).toBe("string");
    expect(refs[0].url).toMatch(/^https:\/\//);
  });

  it("explain_control returns refs alongside the summary", async () => {
    bridge = new PhpBridge({ cliPath: CLI, corpusDefaultPath: CORPUS });
    const r = await bridge.call("explain_control", { control_ref: "ECC-2-3-1" }) as {
      refs: Array<{ title: string; url: string }>;
      sources: string[];
    };
    expect(r.refs.length).toBeGreaterThan(0);
    expect(r.refs[0].url).toMatch(/^https:\/\//);
    expect(r.sources.length).toBeGreaterThan(0);
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

  // SEC-006 extended: non-question args are also validated
  it("rejects non-question arg exceeding 1000 chars", async () => {
    bridge = new PhpBridge({ cliPath: CLI, corpusDefaultPath: CORPUS });
    await expect(bridge.call("compare", { framework_a: "a".repeat(1001), framework_b: "ECC" }))
      .rejects.toThrow(/BAD_REQUEST.*framework_a/);
  });

  it("rejects non-question arg containing control character", async () => {
    bridge = new PhpBridge({ cliPath: CLI, corpusDefaultPath: CORPUS });
    await expect(bridge.call("explain_control", { control_ref: "ECC\x01" }))
      .rejects.toThrow(/BAD_REQUEST.*control_ref.*control characters/);
  });
});

describe("SEC-001 spawn signature", () => {
  beforeEach(() => { spawnCalls.length = 0; });

  it("spawns with shell:false and args as an array", async () => {
    const bridge = new PhpBridge({ cliPath: CLI, corpusDefaultPath: CORPUS });
    // Trigger ensureChild by making a real call.
    await bridge.call("show_corpus", {});
    bridge.shutdown();

    expect(spawnCalls.length).toBeGreaterThan(0);
    const [phpArg, argsArg, optsArg] = spawnCalls[0];
    // 1st arg: php binary path (a string)
    expect(typeof phpArg).toBe("string");
    // 2nd arg: array of arguments (never a single merged string)
    expect(Array.isArray(argsArg)).toBe(true);
    // 3rd arg: options object with shell:false
    expect((optsArg as Record<string, unknown>)?.shell).toBe(false);
  });
});
