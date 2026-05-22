# Saqr MCP v0.1.0 Release Checklist

## Pre-release (one-time GitHub configuration)

- [ ] Create `release` environment in GitHub repo settings → Environments
- [ ] Add NPM_TOKEN as a secret on the `release` environment (npm publish token with publish scope on @cybersafe-lab)
- [ ] Configure workflow permissions: Settings → Actions → General → Workflow permissions → "Read and write" + "Allow GitHub Actions to create and approve pull requests"
- [ ] Add required reviewers to the `release` environment (yourself)

## Pre-release security steps

- [ ] **Pin Docker base image digests (SEC-003).** Run the following and update `Dockerfile` `FROM` lines with `@sha256:<digest>` before tagging:
  ```bash
  docker pull node:20-alpine
  docker inspect node:20-alpine --format '{{index .RepoDigests 0}}'
  docker pull php:8.3-cli-alpine
  docker inspect php:8.3-cli-alpine --format '{{index .RepoDigests 0}}'
  ```

## Cut the release

1. Verify `feat/mcp-v0.1` is green in CI
2. Squash-merge `feat/mcp-v0.1` to `main`
3. Pull main locally: `git checkout main && git pull`
4. Tag: `git tag v0.1.0 && git push origin v0.1.0`
5. Watch the release workflow in the Actions tab — it will pause for manual approval
6. Approve the deployment in the `release` environment

## Post-release verification

- [ ] `npm view @cybersafe-lab/saqr-mcp` shows v0.1.0 with provenance
- [ ] `docker pull ghcr.io/cybersafe-lab/saqr-mcp:0.1.0` succeeds
- [ ] `cosign verify ghcr.io/cybersafe-lab/saqr-mcp:0.1.0 --certificate-identity-regexp 'https://github.com/cybersafe-lab/saqr/.*' --certificate-oidc-issuer 'https://token.actions.githubusercontent.com'` passes
- [ ] Test install in a clean Claude Desktop profile (both npm and Docker channels)

## MCP Registry submission

1. Fork https://github.com/modelcontextprotocol/registry
2. Add `mcp/server.json` to the registry following their PR template
3. Open PR, link to release artifacts
