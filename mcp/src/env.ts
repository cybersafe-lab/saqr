import { existsSync, statSync } from "node:fs";
import { isAbsolute } from "node:path";

const SHELL_META = /[;&|`$()<>"']/;
const ALLOWED_CORPUS_ROOTS = ["/usr/share/saqr/", "/etc/saqr/", "/app/"];

function rejectIfContainsShellMeta(value: string, varName: string): void {
  if (SHELL_META.test(value)) {
    throw new Error(`${varName} contains shell metacharacters`);
  }
  if (value.includes("\0")) {
    throw new Error(`${varName} contains a NUL byte`);
  }
  if (value.includes("..")) {
    throw new Error(`${varName} contains relative components`);
  }
}

export function validatePhpPath(envValue: string | undefined): string {
  if (!envValue || envValue === "") return "php";
  rejectIfContainsShellMeta(envValue, "SAQR_PHP_PATH");
  if (!isAbsolute(envValue)) {
    // Basename allowed (resolved via PATH at spawn time)
    if (envValue.includes("/") || envValue.includes("\\")) {
      throw new Error("SAQR_PHP_PATH must be absolute or a bare basename");
    }
    return envValue;
  }
  if (!existsSync(envValue) || !statSync(envValue).isFile()) {
    throw new Error("SAQR_PHP_PATH does not point at a file");
  }
  return envValue;
}

export function validateCorpusPath(envValue: string | undefined, defaultPath: string): string {
  if (!envValue || envValue === "") return defaultPath;
  rejectIfContainsShellMeta(envValue, "SAQR_CORPUS_PATH");
  if (!isAbsolute(envValue)) {
    throw new Error("SAQR_CORPUS_PATH must be absolute");
  }
  const allowed = ALLOWED_CORPUS_ROOTS.some(prefix => envValue.startsWith(prefix))
    || envValue.startsWith(process.env.HOME ?? "/IMPOSSIBLE/");
  if (!allowed) {
    throw new Error("SAQR_CORPUS_PATH must be under HOME, /usr/share/saqr/, /etc/saqr/, or /app/");
  }
  if (!existsSync(envValue) || !statSync(envValue).isFile()) {
    throw new Error("SAQR_CORPUS_PATH does not point at a file");
  }
  return envValue;
}
