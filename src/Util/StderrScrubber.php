<?php
declare(strict_types=1);

namespace Saqr\Util;

/**
 * Wraps writes to STDERR with a redaction pass so Anthropic API key
 * fragments and absolute paths never reach the MCP client logs.
 */
final class StderrScrubber {
    public static function write(string $line): void {
        // Redact Anthropic key patterns (sk-ant-XXXX...)
        $line = preg_replace('/sk-ant-[A-Za-z0-9_\-]+/', 'sk-ant-***', $line);
        // Redact absolute paths
        $line = preg_replace('#/[A-Za-z0-9_\-./]+#', '<path>', $line);
        fwrite(STDERR, $line);
    }
}
