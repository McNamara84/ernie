<?php

declare(strict_types=1);

use App\Support\DescriptionTextNormalizer;

it('normalizes named, decimal, and hexadecimal angle bracket entities', function (): void {
    $result = (new DescriptionTextNormalizer)->normalize(
        'Values &gt;500, &LT;0.5, &#62;10, &#060;2, &#x3e;7, and &#X03C;9.'
    );

    expect($result)->toBe([
        'value' => 'Values >500, <0.5, >10, <2, >7, and <9.',
        'replacements' => 6,
    ]);
});

it('does not decode unrelated or doubly encoded entities', function (): void {
    $input = 'Keep &amp;gt;, &amp;lt;, &quot;, &copy;, and <literal> unchanged.';

    expect((new DescriptionTextNormalizer)->normalize($input))->toBe([
        'value' => $input,
        'replacements' => 0,
    ]);
});
