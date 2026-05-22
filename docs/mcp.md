# Saqr MCP Integration Guide

## What this is

`@cybersafe-lab/saqr-mcp` is a Model Context Protocol server that exposes
Saqr's Saudi cybersecurity corpus as tools for any MCP-compatible client.

## Install

(Both options shown in the project README.)

## Tools

| Tool | Inputs | What it does |
|---|---|---|
| `saqr_search` | `{question: string}` | Free-text search; top-3 ranked entries with citations |
| `saqr_compare_frameworks` | `{framework_a: string, framework_b: string}` | Crosswalk between two KSA frameworks |
| `saqr_explain_control` | `{control_ref: string}` | Practitioner explanation of a specific control (e.g. ECC-2-3-1) |
| `saqr_show_corpus` | `{}` | List frameworks covered |

## Environment variables

| Var | Purpose | Default |
|---|---|---|
| `ANTHROPIC_API_KEY` | If set, the LLM layer runs. If unset, all tools return curated corpus entries verbatim. | unset |
| `SAQR_CORPUS_PATH` | Override corpus location. Must be absolute, must not contain `..`, must be under `$HOME` / `/usr/share/saqr/` / `/etc/saqr/` / `/app/`. | bundled `corpus/frameworks.json` |
| `SAQR_PHP_PATH` | Override which PHP binary to invoke. Bare basename or absolute path. No shell metacharacters. | `php` (PATH resolved) |
| `SAQR_LOG_LEVEL` | `error` / `warn` / `info` / `debug` | `warn` |

## Trust model

The MCP server runs locally. It spawns one PHP child process per MCP session and only:
- Reads the bundled `corpus/frameworks.json` (or `SAQR_CORPUS_PATH` if set)
- Calls the Anthropic API IF `ANTHROPIC_API_KEY` is set
- Writes JSON-RPC responses to stdout

It does not open network sockets except for the Anthropic API call when explicitly enabled by setting the key.

The corpus is a trust boundary: changes ship via PRs reviewed by maintainers, and a CI corpus-lint job rejects entries containing instruction-shaped strings.

## Troubleshooting

| Symptom | Fix |
|---|---|
| `PHP runtime not found` | Use the Docker option instead, or `brew install php` / `apt install php-cli`. |
| `SAQR_CORPUS_PATH must be under...` | Move your custom corpus into `$HOME/...` or set the env var to the bundled default. |
| Server crashes repeatedly (`saqr backend repeatedly crashing`) | Check `~/.config/claude/logs/` for the underlying PHP error. File an issue with the redacted log. |
