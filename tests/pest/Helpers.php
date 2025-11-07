<?php

use Illuminate\Testing\TestResponse;

/**
 * Helper function to extract XML upload data from session-based response.
 *
 * The UploadXmlController stores data in session to avoid 414 URI Too Long errors.
 * This helper retrieves the session data using the sessionKey from the response.
 *
 * @param  TestResponse  $response  The response from /dashboard/upload-xml endpoint
 * @return array<string, mixed> The uploaded XML data stored in session
 */
function getXmlUploadData(TestResponse $response): array
{
    $response->assertOk();
    $response->assertJsonStructure(['sessionKey']);

    $sessionKey = $response->json('sessionKey');

    if (! is_string($sessionKey)) {
        throw new RuntimeException('Session key must be a string');
    }

    $sessionData = session($sessionKey);

    if (! is_array($sessionData)) {
        throw new RuntimeException("No session data found for key: {$sessionKey}");
    }

    return $sessionData;
}

/**
 * Custom TestResponse macro for asserting XML upload session data.
 * Extends TestResponse to support assertSessionDataPath() for XML uploads.
 */
TestResponse::macro('assertSessionDataPath', function (string $path, mixed $expected) {
    /** @var TestResponse $this */
    $data = getXmlUploadData($this);

    // Convert dot notation path to array access
    $keys = explode('.', $path);
    $value = $data;

    foreach ($keys as $key) {
        if (! is_array($value) || ! array_key_exists($key, $value)) {
            PHPUnit\Framework\Assert::fail("Path '{$path}' not found in session data");
        }
        $value = $value[$key];
    }

    PHPUnit\Framework\Assert::assertSame($expected, $value, "Session data at '{$path}' does not match expected value");

    return $this;
});

/**
 * Get value from session data by path (dot notation).
 */
TestResponse::macro('sessionData', function (?string $path = null) {
    /** @var TestResponse $this */
    $data = getXmlUploadData($this);

    if ($path === null) {
        return $data;
    }

    $keys = explode('.', $path);
    $value = $data;

    foreach ($keys as $key) {
        if (! is_array($value) || ! array_key_exists($key, $value)) {
            return null;
        }
        $value = $value[$key];
    }

    return $value;
});

/**
 * Assert count of items in session data array.
 */
TestResponse::macro('assertSessionDataCount', function (int $expected, string $path) {
    /** @var TestResponse $this */
    $value = $this->sessionData($path);

    if (! is_array($value) && ! ($value instanceof Countable)) {
        PHPUnit\Framework\Assert::fail("Value at '{$path}' is not countable");
    }

    PHPUnit\Framework\Assert::assertCount($expected, $value, "Session data at '{$path}' does not have expected count");

    return $this;
});

/**
 * Assert that session data contains expected subset (like assertJson).
 */
TestResponse::macro('assertSessionData', function (array $expected) {
    /** @var TestResponse $this */
    $data = getXmlUploadData($this);

    // Use PHPUnit's assertArraySubset equivalent
    foreach ($expected as $key => $value) {
        if (! array_key_exists($key, $data)) {
            PHPUnit\Framework\Assert::fail("Key '{$key}' not found in session data");
        }

        if (is_array($value)) {
            assertArraySubset($value, $data[$key]);
        } else {
            PHPUnit\Framework\Assert::assertSame($value, $data[$key], "Value at key '{$key}' does not match");
        }
    }

    return $this;
});

/**
 * Recursive array subset assertion helper.
 */
function assertArraySubset(array $expected, mixed $actual, string $path = ''): void
{
    if (! is_array($actual)) {
        PHPUnit\Framework\Assert::fail("Expected array at '{$path}' but got ".gettype($actual));
    }

    foreach ($expected as $key => $value) {
        $currentPath = $path === '' ? (string) $key : "{$path}.{$key}";

        if (! array_key_exists($key, $actual)) {
            PHPUnit\Framework\Assert::fail("Key '{$currentPath}' not found");
        }

        if (is_array($value)) {
            assertArraySubset($value, $actual[$key], $currentPath);
        } else {
            PHPUnit\Framework\Assert::assertSame($value, $actual[$key], "Value at '{$currentPath}' does not match");
        }
    }
}
