<?php
declare(strict_types=1);

use Saqr\Generator;

test('allowed tags without attributes pass through', function () {
    expect(Generator::sanitizeHtml('<strong>a</strong> and <em>b</em><br>ok'))
        ->toBe('<strong>a</strong> and <em>b</em><br>ok');
});

test('event handler attributes on allowed tags are removed', function () {
    expect(Generator::sanitizeHtml('<strong onclick=alert(1)>x</strong>'))
        ->toBe('<strong>x</strong>');
});

test('style and data attributes on allowed tags are removed', function () {
    expect(Generator::sanitizeHtml('<em style="position:fixed" data-x="1">y</em>'))
        ->toBe('<em>y</em>');
});

test('disallowed elements are unwrapped but their text kept', function () {
    expect(Generator::sanitizeHtml('<div class="x"><strong>ok</strong> rest</div>'))
        ->toBe('<strong>ok</strong> rest');
});

test('script and style elements are dropped with their content', function () {
    expect(Generator::sanitizeHtml('a<script>alert(1)</script>b<style>*{}</style>c'))
        ->toBe('abc');
});

test('svg payloads are removed', function () {
    expect(Generator::sanitizeHtml('<svg onload=alert(1)>x</svg>ok'))
        ->toBe('xok');
});

test('malformed attribute payloads do not survive', function () {
    $out = Generator::sanitizeHtml('<strong onclick="a>b">x</strong>');
    expect($out)->not->toContain('onclick');
    expect($out)->toContain('x');
});

test('text nodes are entity-escaped', function () {
    expect(Generator::sanitizeHtml('1 &lt; 2 &amp; 3'))
        ->toBe('1 &lt; 2 &amp; 3');
});

test('nested allowed tags keep structure, lose attributes', function () {
    expect(Generator::sanitizeHtml('<strong id="a"><em onmouseover=x>deep</em></strong>'))
        ->toBe('<strong><em>deep</em></strong>');
});

test('arabic text survives sanitization intact', function () {
    expect(Generator::sanitizeHtml('<strong>الهيئة الوطنية للأمن السيبراني</strong>'))
        ->toBe('<strong>الهيئة الوطنية للأمن السيبراني</strong>');
});
