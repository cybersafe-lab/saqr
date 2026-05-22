#!/usr/bin/env node
import { cpSync, rmSync, mkdirSync, chmodSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const MCP_DIR = resolve(HERE, '..');
const REPO_ROOT = resolve(MCP_DIR, '..');
const DEST = resolve(MCP_DIR, 'vendor-bundled');

rmSync(DEST, { recursive: true, force: true });
mkdirSync(DEST, { recursive: true });
for (const d of ['bin', 'src', 'corpus']) {
  cpSync(resolve(REPO_ROOT, d), resolve(DEST, d), { recursive: true });
}

// SEC-001: ensure saqr-cli is executable after bundling
try {
  chmodSync(resolve(DEST, 'bin', 'saqr-cli'), 0o755);
} catch { /* non-POSIX (Windows) — skip */ }

console.log('Bundled PHP assets into', DEST);
