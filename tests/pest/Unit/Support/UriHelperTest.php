<?php

declare(strict_types=1);

use App\Support\UriHelper;

describe('UriHelper (PHP 8.5 RFC 3986 URI Parser)', function () {
    describe('parse()', function () {
        it('returns Uri object for valid absolute URL', function () {
            $uri = UriHelper::parse('https://example.com/path?query=1');

            expect($uri)->not->toBeNull()
                ->and($uri->getScheme())->toBe('https')
                ->and($uri->getHost())->toBe('example.com')
                ->and($uri->getPath())->toBe('/path')
                ->and($uri->getQuery())->toBe('query=1');
        });

        it('returns Uri object for URL with port', function () {
            $uri = UriHelper::parse('http://localhost:8080/api');

            expect($uri)->not->toBeNull()
                ->and($uri->getHost())->toBe('localhost')
                ->and($uri->getPort())->toBe(8080);
        });

        it('returns Uri object for URL with userinfo', function () {
            $uri = UriHelper::parse('https://user:pass@example.com/');

            expect($uri)->not->toBeNull()
                ->and($uri->getUserInfo())->toBe('user:pass');
        });

        it('returns Uri object for URL with fragment', function () {
            $uri = UriHelper::parse('https://example.com/page#section');

            expect($uri)->not->toBeNull()
                ->and($uri->getFragment())->toBe('section');
        });

        it('returns Uri object for empty string (path-only URI per RFC 3986)', function () {
            // RFC 3986 allows empty strings as valid relative references
            $uri = UriHelper::parse('');
            expect($uri)->not->toBeNull();
        });

        it('returns null for truly malformed URIs', function () {
            $malformed = [
                'http://[invalid',
                'http://example.com:-1',
            ];

            foreach ($malformed as $uri) {
                $result = UriHelper::parse($uri);
                expect($result)->toBeNull();
            }
        });

        it('parses IPv4 host correctly', function () {
            $uri = UriHelper::parse('http://192.168.1.1:8080/path');

            expect($uri)->not->toBeNull()
                ->and($uri->getHost())->toBe('192.168.1.1')
                ->and($uri->getPort())->toBe(8080);
        });

        it('parses IPv6 host correctly', function () {
            $uri = UriHelper::parse('http://[::1]:8080/path');

            expect($uri)->not->toBeNull()
                ->and($uri->getHost())->toBe('[::1]')
                ->and($uri->getPort())->toBe(8080);
        });
    });

    describe('getScheme()', function () {
        it('returns lowercase scheme for HTTP', function () {
            expect(UriHelper::getScheme('HTTP://EXAMPLE.COM'))->toBe('http');
        });

        it('returns lowercase scheme for HTTPS', function () {
            expect(UriHelper::getScheme('HTTPS://example.com'))->toBe('https');
        });

        it('returns scheme for FTP', function () {
            expect(UriHelper::getScheme('ftp://files.example.com'))->toBe('ftp');
        });

        it('returns null for relative URL', function () {
            expect(UriHelper::getScheme('/path/to/file'))->toBeNull();
        });

        it('returns null for protocol-relative URL', function () {
            expect(UriHelper::getScheme('//example.com/path'))->toBeNull();
        });

        it('returns null for empty string', function () {
            expect(UriHelper::getScheme(''))->toBeNull();
        });

        it('returns null for path-only input', function () {
            expect(UriHelper::getScheme('not-a-url'))->toBeNull();
        });
    });

    describe('getHost()', function () {
        it('returns host for standard URL', function () {
            expect(UriHelper::getHost('https://www.example.com/path'))->toBe('www.example.com');
        });

        it('returns host without port', function () {
            expect(UriHelper::getHost('https://example.com:443/path'))->toBe('example.com');
        });

        it('returns lowercase host', function () {
            expect(UriHelper::getHost('https://EXAMPLE.COM/'))->toBe('example.com');
        });

        it('returns null for relative path', function () {
            expect(UriHelper::getHost('/just/a/path'))->toBeNull();
        });

        it('returns null for empty string', function () {
            expect(UriHelper::getHost(''))->toBeNull();
        });

        it('handles localhost', function () {
            expect(UriHelper::getHost('http://localhost:3000/'))->toBe('localhost');
        });

        it('handles subdomain', function () {
            expect(UriHelper::getHost('https://api.v1.example.com/'))->toBe('api.v1.example.com');
        });
    });

    describe('getPath()', function () {
        it('returns path with leading slash', function () {
            expect(UriHelper::getPath('https://example.com/path/to/resource'))->toBe('/path/to/resource');
        });

        it('returns root path for domain only', function () {
            expect(UriHelper::getPath('https://example.com/'))->toBe('/');
        });

        it('returns empty string for domain without trailing slash', function () {
            $path = UriHelper::getPath('https://example.com');
            expect($path)->toBe('');
        });

        it('returns path with file extension', function () {
            expect(UriHelper::getPath('https://example.com/file.html'))->toBe('/file.html');
        });

        it('returns path excluding query string', function () {
            expect(UriHelper::getPath('https://example.com/search?q=test'))->toBe('/search');
        });

        it('returns path excluding fragment', function () {
            expect(UriHelper::getPath('https://example.com/page#section'))->toBe('/page');
        });

        it('returns empty string for empty string input', function () {
            // RFC 3986: empty string is a valid relative reference with empty path
            expect(UriHelper::getPath(''))->toBe('');
        });

        it('handles encoded characters in path', function () {
            $path = UriHelper::getPath('https://example.com/path%20with%20spaces');
            expect($path)->toBe('/path%20with%20spaces');
        });

        it('handles relative path input', function () {
            $path = UriHelper::getPath('/relative/path');
            expect($path)->toBe('/relative/path');
        });
    });

    describe('getQuery()', function () {
        it('returns query string without question mark', function () {
            expect(UriHelper::getQuery('https://example.com/search?q=test&page=1'))->toBe('q=test&page=1');
        });

        it('returns null when no query string', function () {
            expect(UriHelper::getQuery('https://example.com/path'))->toBeNull();
        });

        it('returns empty string for empty query', function () {
            $query = UriHelper::getQuery('https://example.com/path?');
            expect($query)->toBe('');
        });

        it('returns query excluding fragment', function () {
            expect(UriHelper::getQuery('https://example.com/path?q=1#section'))->toBe('q=1');
        });

        it('handles encoded values in query', function () {
            expect(UriHelper::getQuery('https://example.com/?search=hello%20world'))->toBe('search=hello%20world');
        });

        it('returns null for empty string', function () {
            expect(UriHelper::getQuery(''))->toBeNull();
        });
    });

    describe('getQueryParams()', function () {
        it('returns parsed query parameters as array', function () {
            $params = UriHelper::getQueryParams('https://example.com/search?q=test&page=1');

            expect($params)
                ->toBeArray()
                ->toHaveKey('q', 'test')
                ->toHaveKey('page', '1');
        });

        it('returns empty array when no query string', function () {
            expect(UriHelper::getQueryParams('https://example.com/path'))->toBe([]);
        });

        it('handles array parameters', function () {
            $params = UriHelper::getQueryParams('https://example.com/?items[]=a&items[]=b');

            expect($params)->toBeArray()
                ->and($params['items'])->toBeArray()
                ->and($params['items'])->toContain('a', 'b');
        });

        it('decodes URL-encoded values', function () {
            $params = UriHelper::getQueryParams('https://example.com/?name=John%20Doe');

            expect($params)->toBeArray()
                ->toHaveKey('name', 'John Doe');
        });

        it('returns empty array for empty string', function () {
            expect(UriHelper::getQueryParams(''))->toBe([]);
        });

        it('enforces DoS protection limit (8KB)', function () {
            // Create a query string longer than 8KB
            $longQuery = 'x='.str_repeat('a', 9000);
            $params = UriHelper::getQueryParams('https://example.com/?'.$longQuery);

            expect($params)->toBe([]);
        });
    });

    describe('isValid()', function () {
        it('returns true for valid HTTP URL', function () {
            expect(UriHelper::isValid('http://example.com'))->toBeTrue();
        });

        it('returns true for valid HTTPS URL', function () {
            expect(UriHelper::isValid('https://example.com/path?query=1#hash'))->toBeTrue();
        });

        it('returns true for valid FTP URL', function () {
            expect(UriHelper::isValid('ftp://files.example.com/file.txt'))->toBeTrue();
        });

        it('returns true for empty string (valid relative reference per RFC 3986)', function () {
            // RFC 3986 allows empty strings as valid relative references
            expect(UriHelper::isValid(''))->toBeTrue();
        });

        it('returns true for relative path (valid per RFC 3986)', function () {
            // RFC 3986 considers relative paths as valid references
            expect(UriHelper::isValid('/relative/path'))->toBeTrue();
        });

        it('returns false for invalid IPv6', function () {
            expect(UriHelper::isValid('http://[invalid'))->toBeFalse();
        });
    });

    describe('hasScheme()', function () {
        it('returns true for URL with matching scheme', function () {
            expect(UriHelper::hasScheme('https://example.com', 'https'))->toBeTrue();
        });

        it('returns true when scheme matches one in array', function () {
            expect(UriHelper::hasScheme('https://example.com', ['http', 'https']))->toBeTrue();
        });

        it('returns false for protocol-relative URL', function () {
            expect(UriHelper::hasScheme('//example.com/path', 'https'))->toBeFalse();
        });

        it('returns false for relative path', function () {
            expect(UriHelper::hasScheme('/path/to/file', 'https'))->toBeFalse();
        });

        it('returns false for empty string', function () {
            expect(UriHelper::hasScheme('', 'https'))->toBeFalse();
        });

        it('matches case-insensitively', function () {
            expect(UriHelper::hasScheme('HTTPS://example.com', 'https'))->toBeTrue();
            expect(UriHelper::hasScheme('https://example.com', 'HTTPS'))->toBeTrue();
        });
    });

    describe('isHttpUrl()', function () {
        it('returns true for HTTP URL', function () {
            expect(UriHelper::isHttpUrl('http://example.com'))->toBeTrue();
        });

        it('returns true for HTTPS URL', function () {
            expect(UriHelper::isHttpUrl('https://example.com'))->toBeTrue();
        });

        it('returns true for uppercase HTTP', function () {
            expect(UriHelper::isHttpUrl('HTTP://EXAMPLE.COM'))->toBeTrue();
        });

        it('returns false for FTP URL', function () {
            expect(UriHelper::isHttpUrl('ftp://files.example.com'))->toBeFalse();
        });

        it('returns false for mailto URL', function () {
            expect(UriHelper::isHttpUrl('mailto:user@example.com'))->toBeFalse();
        });

        it('returns false for empty string', function () {
            expect(UriHelper::isHttpUrl(''))->toBeFalse();
        });

        it('returns false for relative path', function () {
            expect(UriHelper::isHttpUrl('/path/to/resource'))->toBeFalse();
        });

        it('returns true for HTTPS with port', function () {
            expect(UriHelper::isHttpUrl('https://example.com:8443/api'))->toBeTrue();
        });
    });

    describe('edge cases', function () {
        it('handles data URI', function () {
            $dataUri = 'data:text/plain;base64,SGVsbG8gV29ybGQ=';
            $scheme = UriHelper::getScheme($dataUri);

            expect($scheme)->toBe('data');
        });

        it('handles file URI', function () {
            expect(UriHelper::getScheme('file:///path/to/file.txt'))->toBe('file');
        });

        it('handles mailto URI', function () {
            expect(UriHelper::getScheme('mailto:user@example.com'))->toBe('mailto');
        });

        it('handles very long URLs gracefully', function () {
            $longPath = '/'.str_repeat('a', 2000);
            $uri = 'https://example.com'.$longPath;

            $path = UriHelper::getPath($uri);
            expect($path)->toBe($longPath);
        });

        it('handles null-byte in URL safely', function () {
            // Should not crash or throw unexpected exceptions
            $result = UriHelper::parse("https://example.com/path\x00injection");
            // Result may vary but should be safe
            expect(true)->toBeTrue();
        });

        it('handles whitespace-only input', function () {
            // Whitespace is valid in relative references
            expect(UriHelper::getScheme('   '))->toBeNull();
            expect(UriHelper::getHost('   '))->toBeNull();
        });
    });

    describe('ROR ID specific scenarios', function () {
        it('extracts path from ror.org URL', function () {
            $path = UriHelper::getPath('https://ror.org/02t274463');

            expect($path)->toBe('/02t274463');
        });

        it('returns ror.org as host', function () {
            $host = UriHelper::getHost('https://ror.org/02t274463');

            expect($host)->toBe('ror.org');
        });

        it('handles ROR URL with www prefix', function () {
            $host = UriHelper::getHost('https://www.ror.org/02t274463');

            expect($host)->toBe('www.ror.org');
        });

        it('distinguishes ROR host from path containing ror.org', function () {
            // This is the edge case that was causing issues
            $fakeRorUrl = 'https://example.com/ror.org/fakeid';

            expect(UriHelper::getHost($fakeRorUrl))->toBe('example.com');
            expect(UriHelper::getPath($fakeRorUrl))->toBe('/ror.org/fakeid');
        });
    });
});
