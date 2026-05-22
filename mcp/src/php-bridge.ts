import { spawn, type ChildProcessWithoutNullStreams } from "node:child_process";
import { createInterface, type Interface } from "node:readline";
import { randomUUID } from "node:crypto";
import { validatePhpPath, validateCorpusPath } from "./env.js";
import { scrub } from "./scrubber.js";

const REQUEST_TIMEOUT_MS = 10_000;
const MAX_QUESTION_LEN = 1_000;
// SEC-006: control chars 0x00-0x1F, excluding \t (0x09) and \n (0x0A).
const CONTROL_CHARS = /[\x00-\x08\x0B-\x1F]/;

interface Pending {
  resolve: (v: unknown) => void;
  reject: (e: Error) => void;
  timeoutId: NodeJS.Timeout;
}

interface BridgeOptions {
  cliPath: string;          // absolute path to bin/saqr-cli
  corpusDefaultPath: string;
}

export class PhpBridge {
  private child: ChildProcessWithoutNullStreams | null = null;
  private rl: Interface | null = null;
  private pending = new Map<string, Pending>();
  private respawnTimestamps: number[] = [];
  private readonly opts: BridgeOptions;
  private readonly env: NodeJS.ProcessEnv;
  private readonly phpPath: string;

  constructor(opts: BridgeOptions) {
    this.opts = opts;
    this.phpPath = validatePhpPath(process.env.SAQR_PHP_PATH);
    const corpus = validateCorpusPath(process.env.SAQR_CORPUS_PATH, opts.corpusDefaultPath);
    this.env = { ...process.env, SAQR_CORPUS_PATH: corpus };
  }

  /** SEC-001: spawn with shell:false, args as array. Never exec. */
  private async ensureChild(): Promise<void> {
    if (this.child && !this.child.killed) return;
    const now = Date.now();
    this.respawnTimestamps = this.respawnTimestamps.filter(t => now - t < 60_000);
    if (this.respawnTimestamps.length >= 3) {
      throw new Error("INTERNAL: saqr backend repeatedly crashing");
    }
    this.respawnTimestamps.push(now);

    this.child = spawn(this.phpPath, [this.opts.cliPath, "serve"], {
      shell: false,
      stdio: ["pipe", "pipe", "pipe"],
      env: this.env,
      detached: false,
    });
    this.child.stderr.on("data", (buf: Buffer) => {
      process.stderr.write(scrub(buf.toString()));
    });
    this.child.on("exit", code => {
      const err = new Error(`INTERNAL: php child exited (code ${code})`);
      for (const p of this.pending.values()) {
        clearTimeout(p.timeoutId);
        p.reject(err);
      }
      this.pending.clear();
      this.child = null;
      this.rl = null;
    });
    this.rl = createInterface({ input: this.child.stdout });
    this.rl.on("line", line => this.handleLine(line));
  }

  private handleLine(line: string): void {
    let msg: { id?: unknown; ok?: unknown; result?: unknown; code?: unknown; error?: unknown };
    try { msg = JSON.parse(line); } catch { return; }
    const id = typeof msg?.id === "string" ? msg.id : undefined;
    const p = id ? this.pending.get(id) : undefined;
    if (!p) return;
    clearTimeout(p.timeoutId);
    this.pending.delete(id!);
    if (msg.ok === true) {
      p.resolve(msg.result);
    } else {
      const code = typeof msg.code === "string" ? msg.code : "INTERNAL";
      const errStr = typeof msg.error === "string" ? msg.error : "";
      p.reject(new Error(`${code}: ${scrub(errStr)}`));
    }
  }

  async call(cmd: string, args: Record<string, unknown>): Promise<unknown> {
    // SEC-006: input bounds on question-style fields
    if (typeof args.question === "string") {
      if (args.question.length > MAX_QUESTION_LEN) {
        throw new Error(`BAD_REQUEST: question exceeds ${MAX_QUESTION_LEN} chars`);
      }
      if (CONTROL_CHARS.test(args.question)) {
        throw new Error("BAD_REQUEST: question contains control characters");
      }
    }
    await this.ensureChild();
    const id = randomUUID();
    const req = JSON.stringify({ id, cmd, args }) + "\n";
    return new Promise((resolve, reject) => {
      const timeoutId = setTimeout(() => {
        this.pending.delete(id);
        this.killChild();
        reject(new Error("TIMEOUT: PHP child did not respond within 10s"));
      }, REQUEST_TIMEOUT_MS);
      this.pending.set(id, { resolve, reject, timeoutId });
      try {
        this.child!.stdin.write(req);
      } catch (e) {
        clearTimeout(timeoutId);
        this.pending.delete(id);
        reject(new Error(`INTERNAL: failed to write to PHP child: ${scrub(String(e))}`));
      }
    });
  }

  /** SEC-005: SIGKILL + (POSIX) process-group kill */
  killChild(): void {
    if (!this.child) return;
    try {
      if (process.platform !== "win32" && this.child.pid) {
        process.kill(-this.child.pid, "SIGKILL");
      } else {
        this.child.kill("SIGKILL");
      }
    } catch { /* ignore */ }
    this.child = null;
    this.rl = null;
  }

  shutdown(): void {
    for (const p of this.pending.values()) {
      clearTimeout(p.timeoutId);
      p.reject(new Error("SHUTDOWN"));
    }
    this.pending.clear();
    this.killChild();
  }
}
