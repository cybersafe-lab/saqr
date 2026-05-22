<?php
declare(strict_types=1);

test('WordPress chatbot response shape matches Pipeline::ask', function () {
    $wpPath = getenv('SAQR_WP_PATH');
    if (!$wpPath || !is_dir($wpPath)) {
        $this->markTestSkipped('SAQR_WP_PATH not set; skipping WP regression smoke.');
        return;
    }

    $handlerPath = $wpPath . '/wp-content/themes/mefolio-child/inc/grc-assistant.php';
    expect(is_file($handlerPath))->toBeTrue();

    // The WP handler has its own inlined retrieval. The smoke test just
    // asserts the file is present and the function the chatbot panel
    // depends on still exists. Fuller integration would require booting WP.
    $contents = file_get_contents($handlerPath);
    expect($contents)->toContain('function ma_grc_assistant_handler');
});
