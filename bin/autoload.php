<?php
/**
 * Hand-rolled PSR-4 autoloader for the npm channel — ships without Composer.
 * Composer's vendor/autoload.php takes precedence when present.
 */
spl_autoload_register(function (string $class): void {
    if (strncmp($class, 'Saqr\\', 5) !== 0) {
        return;
    }
    $relative = substr($class, 5);
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
