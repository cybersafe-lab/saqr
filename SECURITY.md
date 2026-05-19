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
