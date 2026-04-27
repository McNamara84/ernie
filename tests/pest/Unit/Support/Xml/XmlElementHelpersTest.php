<?php

declare(strict_types=1);

use App\Support\Xml\XmlElementHelpers;
use Saloon\XmlWrangler\Data\Element;
use Saloon\XmlWrangler\XmlReader;

covers(XmlElementHelpers::class);

/**
 * Build an XmlReader for the given DataCite-style fragment.
 */
function helpersXml(string $body): XmlReader
{
    $xml = <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <resource xmlns="http://datacite.org/schema/kernel-4">
        {$body}
    </resource>
    XML;

    return XmlReader::fromString($xml);
}

// =========================================================================
// firstStringFromQuery / firstElementFromQuery
// =========================================================================

it('extracts a string value from an xpath query', function (): void {
    $reader = helpersXml('<publicationYear>2026</publicationYear>');
    $value = XmlElementHelpers::firstStringFromQuery(
        $reader->xpathValue('//*[local-name()="publicationYear"]'),
    );

    expect($value)->toBe('2026');
});

it('returns null when the xpath query has no result', function (): void {
    $reader = helpersXml('<publicationYear>2026</publicationYear>');
    $value = XmlElementHelpers::firstStringFromQuery(
        $reader->xpathValue('//*[local-name()="missing"]'),
    );

    expect($value)->toBeNull();
});

it('coerces numeric query results to string', function (): void {
    $fakeQuery = new class
    {
        public function first(): float
        {
            return 1.5;
        }
    };

    expect(XmlElementHelpers::firstStringFromQuery($fakeQuery))->toBe('1.5');
});

it('returns null for non-object query input', function (): void {
    expect(XmlElementHelpers::firstStringFromQuery(null))->toBeNull()
        ->and(XmlElementHelpers::firstStringFromQuery('plain'))->toBeNull()
        ->and(XmlElementHelpers::firstStringFromQuery(42))->toBeNull();
});

it('extracts the first element from an xpath element query', function (): void {
    $reader = helpersXml('<creators><creator><creatorName>Doe, Jane</creatorName></creator></creators>');
    $element = XmlElementHelpers::firstElementFromQuery(
        $reader->xpathElement('//*[local-name()="creator"]'),
    );

    expect($element)->toBeInstanceOf(Element::class);
});

it('returns null when first element query yields no element', function (): void {
    expect(XmlElementHelpers::firstElementFromQuery(null))->toBeNull();
});

// =========================================================================
// localName / containsElements
// =========================================================================

it('strips namespace prefixes and digit suffixes from xml wrangler keys', function (string $input, string $expected): void {
    expect(XmlElementHelpers::localName($input))->toBe($expected);
})->with([
    'plain key' => ['title', 'title'],
    'attribute prefix' => ['@xml:lang', 'lang'],
    'namespace' => ['ns:creator', 'creator'],
    'numbered sibling' => ['title.1', 'title'],
    'namespaced sibling' => ['ns:title.42', 'title'],
    'non-numeric dot' => ['file.name', 'file.name'],
]);

it('detects whether an array contains element instances', function (): void {
    $reader = helpersXml('<a><b>x</b></a>');
    $element = XmlElementHelpers::firstElementFromQuery($reader->xpathElement('//*[local-name()="a"]'));

    expect(XmlElementHelpers::containsElements([1, 'string', null]))->toBeFalse()
        ->and(XmlElementHelpers::containsElements([$element]))->toBeTrue();
});

// =========================================================================
// childElements / firstChildElement / scalarChild
// =========================================================================

it('returns direct child elements by local name', function (): void {
    $reader = helpersXml(
        '<titles><title>One</title><title>Two</title><title titleType="Subtitle">Three</title></titles>'
    );
    $titles = XmlElementHelpers::firstElementFromQuery(
        $reader->xpathElement('//*[local-name()="titles"]'),
    );
    expect($titles)->toBeInstanceOf(Element::class);
    assert($titles instanceof Element);

    $children = XmlElementHelpers::childElements($titles, 'title');

    expect($children)->toHaveCount(3);
    expect(XmlElementHelpers::stringValue($children[0]))->toBe('One');
    expect(XmlElementHelpers::stringValue($children[2]))->toBe('Three');
});

it('returns an empty array for non-array element content', function (): void {
    $reader = helpersXml('<title>Solo</title>');
    $title = XmlElementHelpers::firstElementFromQuery(
        $reader->xpathElement('//*[local-name()="title"]'),
    );
    expect($title)->toBeInstanceOf(Element::class);
    assert($title instanceof Element);

    expect(XmlElementHelpers::childElements($title, 'anything'))->toBe([]);
});

it('returns the first matching child element or null', function (): void {
    $reader = helpersXml('<wrap><a>1</a><a>2</a></wrap>');
    $wrap = XmlElementHelpers::firstElementFromQuery(
        $reader->xpathElement('//*[local-name()="wrap"]'),
    );
    expect($wrap)->toBeInstanceOf(Element::class);
    assert($wrap instanceof Element);

    expect(XmlElementHelpers::stringValue(XmlElementHelpers::firstChildElement($wrap, 'a')))->toBe('1')
        ->and(XmlElementHelpers::firstChildElement($wrap, 'missing'))->toBeNull();
});

it('returns scalar child values as trimmed strings', function (): void {
    $reader = helpersXml('<wrap><name>  Alice  </name><empty></empty></wrap>');
    $wrap = XmlElementHelpers::firstElementFromQuery(
        $reader->xpathElement('//*[local-name()="wrap"]'),
    );
    expect($wrap)->toBeInstanceOf(Element::class);
    assert($wrap instanceof Element);

    expect(XmlElementHelpers::scalarChild($wrap, 'name'))->toBe('Alice')
        ->and(XmlElementHelpers::scalarChild($wrap, 'empty'))->toBeNull()
        ->and(XmlElementHelpers::scalarChild($wrap, 'missing'))->toBeNull();
});

// =========================================================================
// firstElementByKey / allElementsByKey / normaliseToElementList
// =========================================================================

it('looks up elements by xml-wrangler content key', function (): void {
    $reader = helpersXml(
        '<creator><creatorName>Doe, Jane</creatorName><givenName>Jane</givenName><familyName>Doe</familyName></creator>'
    );
    $creator = XmlElementHelpers::firstElementFromQuery(
        $reader->xpathElement('//*[local-name()="creator"]'),
    );
    expect($creator)->toBeInstanceOf(Element::class);
    assert($creator instanceof Element);
    $content = $creator->getContent();
    expect($content)->toBeArray();

    /** @var array<string, mixed> $content */
    $given = XmlElementHelpers::firstElementByKey($content, 'givenName');

    expect($given)->toBeInstanceOf(Element::class);
    expect(XmlElementHelpers::stringValue($given))->toBe('Jane');
    expect(XmlElementHelpers::firstElementByKey($content, 'missing'))->toBeNull();
    expect(XmlElementHelpers::allElementsByKey($content, 'missing'))->toBe([]);
});

it('normalises mixed values into a flat element list', function (): void {
    expect(XmlElementHelpers::normaliseToElementList(null))->toBe([])
        ->and(XmlElementHelpers::normaliseToElementList('scalar'))->toBe([])
        ->and(XmlElementHelpers::normaliseToElementList(['nope', 1]))->toBe([]);
});

// =========================================================================
// stringValue
// =========================================================================

it('returns null for non-element input', function (): void {
    expect(XmlElementHelpers::stringValue(null))->toBeNull();
});

it('concatenates nested element string values', function (): void {
    $reader = helpersXml('<wrap><a>Hello</a><a>  World </a></wrap>');
    $wrap = XmlElementHelpers::firstElementFromQuery(
        $reader->xpathElement('//*[local-name()="wrap"]'),
    );

    expect(XmlElementHelpers::stringValue($wrap))->toBe('Hello World');
});

// =========================================================================
// stringOrNull / intOrNull
// =========================================================================

it('normalises whitespace-only strings to null', function (?string $input, ?string $expected): void {
    expect(XmlElementHelpers::stringOrNull($input))->toBe($expected);
})->with([
    'null'        => [null, null],
    'empty'       => ['', null],
    'whitespace'  => ['   ', null],
    'value'       => ['  hi  ', 'hi'],
]);

it('parses strict integers, otherwise null', function (?string $input, ?int $expected): void {
    expect(XmlElementHelpers::intOrNull($input))->toBe($expected);
})->with([
    'null'      => [null, null],
    'empty'     => ['', null],
    'positive'  => ['42', 42],
    'negative'  => ['-7', -7],
    'with ws'   => ['  19  ', 19],
    'float'     => ['1.5', null],
    'mixed'     => ['12abc', null],
    'word'      => ['twelve', null],
]);

// =========================================================================
// splitCreatorName
// =========================================================================

it('splits "Family, Given" name strings', function (?string $input, array $expected): void {
    expect(XmlElementHelpers::splitCreatorName($input))->toBe($expected);
})->with([
    'comma form'    => ['Doe, Jane', ['familyName' => 'Doe', 'givenName' => 'Jane']],
    'family only'   => ['Doe', ['familyName' => 'Doe', 'givenName' => null]],
    'empty given'   => ['Doe, ', ['familyName' => 'Doe', 'givenName' => null]],
    'empty family'  => [', Jane', ['familyName' => null, 'givenName' => 'Jane']],
    'null input'    => [null, ['givenName' => null, 'familyName' => null]],
    'empty input'   => ['', ['givenName' => null, 'familyName' => null]],
]);
