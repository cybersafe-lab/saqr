# Security Policy

## Reporting a Vulnerability

Report security issues to **security@malyahya.com**.

Coordinated disclosure is preferred; please give a reasonable window before publishing.

Reports are acknowledged within 48 hours and critical issues are aimed to be remediated within 30 days.

## Scope

In scope:

- Code in `src/`
- Default system prompt in `src/Generator.php` (prompt-injection bypasses)
- Corpus parsing / validation (`src/Corpus.php`)

Out of scope:

- Issues in the curated corpus content itself (those are content issues — open a regular issue or PR)
- Issues in user-supplied custom system prompts, custom rate-limiter implementations, or custom corpus files
- Anthropic API itself (report to Anthropic)

## Reproducer

A minimal reproducer is appreciated. CLI examples are easier to triage than full-stack ones; `examples/cli.php` is a good starting point.

## Threat model (v0.1)

The MCP server runs locally on a user's machine, invoked by an MCP
client (e.g. Claude Desktop). Threat vectors considered:

- **Command injection via tool arguments** — the TS wrapper always
  uses `child_process.spawn` with `shell: false` and arguments as an
  array. No exec, no string composition. Pinned by a Vitest test.
- **Env-var path injection** — `SAQR_PHP_PATH` and `SAQR_CORPUS_PATH`
  are validated against allowed prefixes, reject shell metacharacters,
  reject NUL bytes, reject relative `..` components.
- **API key leakage in error output** — top-level try/catch in PHP
  `serve` mode emits only typed error codes. A scrubber redacts
  `sk-ant-*` patterns from all stderr.
- **Subprocess hang / DoS** — 10s per-request timeout with SIGKILL +
  POSIX process-group kill. Maximum 3 respawns within 60s before
  the server returns a hard error.
- **Corpus prompt injection** — the corpus is a trust boundary.
  Corpus PRs require maintainer review; CI `corpus-lint` rejects
  instruction-shaped strings.
- **Supply chain** — npm publish with `--provenance` (Sigstore);
  Docker images signed with cosign keyless; all GH Actions pinned
  by SHA; release workflow requires manual approval.

Out of scope for v0.1:
- Remote/hosted MCP servers (stdio only)
- WebAssembly distribution
- Bilingual READMEs (Arabic translation planned for v0.2)
