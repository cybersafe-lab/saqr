// SEC-004: redact key fragments + absolute paths from any string before
// surfacing it to the MCP client.
const KEY_PATTERN = /sk-ant-[A-Za-z0-9_\-]+/g;
const PATH_PATTERN = /\/[A-Za-z0-9_\-./]+/g;

export function scrub(line: string): string {
  return line.replace(KEY_PATTERN, "sk-ant-***").replace(PATH_PATTERN, "<path>");
}
