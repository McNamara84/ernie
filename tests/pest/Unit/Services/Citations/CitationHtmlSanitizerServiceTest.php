<?php

declare(strict_types=1);

use App\Services\Citations\CitationHtmlSanitizerService;

covers(CitationHtmlSanitizerService::class);

it('extracts exactly the first bibliography entry and keeps CSL typography', function () {
    $html = <<<'HTML'
<div class="csl-bib-body">
  <div class="csl-entry">Müller, <i>Italic</i>, <b>Bold</b>, H<sub>2</sub>O and x<sup>2</sup>.</div>
  <div class="csl-entry">This second entry must not survive.</div>
</div>
HTML;

    $sanitized = (new CitationHtmlSanitizerService)->sanitize($html);

    expect($sanitized)
        ->toStartWith('<div class="csl-entry">')
        ->toContain('Müller, <i>Italic</i>, <b>Bold</b>, H<sub>2</sub>O and x<sup>2</sup>.')
        ->not->toContain('csl-bib-body')
        ->not->toContain('second entry');
});

it('keeps only safe HTTPS links and strips every source attribute', function () {
    $html = <<<'HTML'
<div class="csl-entry">
  <a href="https://doi.org/10.1234/example" title="metadata" target="_blank" onclick="alert(1)">DOI</a>
  <a href="http://example.com/insecure">Insecure</a>
  <a href="javascript:alert(1)">Unsafe</a>
  <a href="https://user:secret@example.com/private">Credentials</a>
</div>
HTML;

    $sanitized = (new CitationHtmlSanitizerService)->sanitize($html);

    expect($sanitized)
        ->toContain('<a href="https://doi.org/10.1234/example" rel="noopener noreferrer">DOI</a>')
        ->not->toContain('onclick')
        ->not->toContain('target=')
        ->not->toContain('title=')
        ->not->toContain('http://')
        ->not->toContain('javascript:')
        ->not->toContain('user:secret')
        ->toContain('Insecure')
        ->toContain('Unsafe')
        ->toContain('Credentials');
});

it('translates known small caps styling to an app class and rejects arbitrary CSS', function () {
    $html = <<<'HTML'
<div class="csl-entry">
  <span style="font-variant: small-caps; color: red; background-image: url(javascript:alert(1))" onclick="alert(2)">GFZ</span>
  <span style="color: red" class="hostile">plain</span>
</div>
HTML;

    $sanitized = (new CitationHtmlSanitizerService)->sanitize($html);

    expect($sanitized)
        ->toContain('<span class="csl-small-caps">GFZ</span>')
        ->toContain('plain')
        ->not->toContain('style=')
        ->not->toContain('onclick')
        ->not->toContain('hostile')
        ->not->toContain('javascript:');
});

it('drops active elements with their contents and unwraps unknown presentational tags', function () {
    $html = <<<'HTML'
<div class="csl-entry">
  Before
  <script>visibleScriptText()</script>
  <style>.leak { display: block }</style>
  <svg><text>svg payload</text></svg>
  <mark data-action="attack">retained text</mark>
  After
</div>
HTML;

    $sanitized = (new CitationHtmlSanitizerService)->sanitize($html);

    expect($sanitized)
        ->toContain('Before')
        ->toContain('retained text')
        ->toContain('After')
        ->not->toContain('visibleScriptText')
        ->not->toContain('display: block')
        ->not->toContain('svg payload')
        ->not->toContain('<mark');
});

it('maps trusted citeproc layout CSS and second-field markup to fixed classes', function () {
    $html = <<<'HTML'
<div class="csl-bib-body">
  <div class="csl-entry custom-class">
    <div class="csl-left-margin hostile">[1]</div>
    <div class="csl-right-inline" style="position: fixed">Citation</div>
  </div>
</div>
HTML;
    $css = <<<'CSS'
.csl-entry {
  line-height: 2em;
  padding-left: 2em;
  text-indent: -2em;
  background-image: url(javascript:alert(1));
}
CSS;

    $sanitized = (new CitationHtmlSanitizerService)->sanitize($html, $css);

    expect($sanitized)
        ->toStartWith('<div class="csl-entry csl-hanging-indent csl-double-spaced">')
        ->toContain('<div class="csl-left-margin">[1]</div>')
        ->toContain('<div class="csl-right-inline">Citation</div>')
        ->not->toContain('custom-class')
        ->not->toContain('hostile')
        ->not->toContain('style=')
        ->not->toContain('javascript:');
});

it('derives deterministic clipboard text from sanitized visible DOM content', function () {
    $sanitizer = new CitationHtmlSanitizerService;
    $sanitized = $sanitizer->sanitize(
        '<div class="csl-entry"><div class="csl-left-margin">[1]</div>'
        .'<div class="csl-right-inline">Müller&nbsp; et al. '
        .'<a href="https://doi.org/10.1/x">https://doi.org/10.1/x</a><br>Tail</div></div>',
    );

    expect($sanitizer->toPlainText($sanitized))
        ->toBe('[1] Müller et al. https://doi.org/10.1/x Tail');
});

it('rejects processor output without a bibliography entry', function () {
    expect(fn () => (new CitationHtmlSanitizerService)->sanitize('<div class="csl-bib-body"></div>'))
        ->toThrow(UnexpectedValueException::class, 'no bibliography entry');
});

it('rejects plaintext input without a sanitized bibliography entry', function () {
    expect(fn () => (new CitationHtmlSanitizerService)->toPlainText('<p>not a citation</p>'))
        ->toThrow(UnexpectedValueException::class, 'no bibliography entry');
});
