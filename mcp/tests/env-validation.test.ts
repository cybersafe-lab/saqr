import { describe, it, expect } from "vitest";
import { validatePhpPath, validateCorpusPath } from "../src/env.js";

describe("validatePhpPath", () => {
  it("returns 'php' when env is unset", () => {
    expect(validatePhpPath(undefined)).toBe("php");
  });
  it("rejects shell metacharacters", () => {
    expect(() => validatePhpPath("php; rm -rf /")).toThrow(/shell/);
  });
  it("rejects NUL byte", () => {
    expect(() => validatePhpPath("php\0")).toThrow(/NUL/);
  });
  it("accepts a bare basename", () => {
    expect(validatePhpPath("php8.3")).toBe("php8.3");
  });
  it("rejects relative path with slash", () => {
    expect(() => validatePhpPath("./bin/php")).toThrow(/absolute/);
  });
});

describe("validateCorpusPath", () => {
  it("uses default when unset", () => {
    expect(validateCorpusPath(undefined, "/default/corpus.json")).toBe("/default/corpus.json");
  });
  it("rejects /etc/passwd", () => {
    expect(() => validateCorpusPath("/etc/passwd", "/d.json")).toThrow(/under HOME/);
  });
  it("rejects relative .. components", () => {
    expect(() => validateCorpusPath("/usr/share/saqr/../etc/passwd", "/d.json")).toThrow(/relative/);
  });
});
