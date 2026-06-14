<?php

declare(strict_types=1);

use App\Services\SizeFormatServiceTest;
use Illuminate\Support\Facades\Http;

// Wenn der Content-Type und Content-Length vorhanden sind, sollen diese verwendet werden
test('it prefers head request for file metadata', function (): void {

    // Fake-Webserver.
    // Statt wirklich ins Internet zu gehen, antwortet Laravel automatisch:
    /**
     * Status: 200
     * Content-Type: application/json
     * Content-Length: 2048
     */
    Http::fake([
        'https://files.example.org/data.json' => Http::response('', 200, [
            'Content-Type' => 'application/json',
            'Content-Length' => '2048',
        ]),
    ]);

    // Service-Objekt wird erstellt
    $service = app(SizeFormatServiceTest::class);

    /**
     * HEAD Request
     * ↓
     * Content-Type lesen
     * ↓
     * Content-Length lesen
     * ↓
     * Suggestions erzeugen
     */
    $result = $service->inferMetadataFromFileUrl(
        'https://files.example.org/data.json'
    );

    // prüft: wurde der HEAD-Weg benutzt?
    expect($result['probe_method'])->toBe('HTTP_HEAD');
    // prüft: Format = application/json?
    expect($result['suggestions'][0]['inferred_value'])->toBe('application/json');
    /**
     * 2048 Bytes
     * ↓
     * 2 KB
     */
    expect($result['suggestions'][1]['inferred_value'])->toBe('2 KB');
});

// wenn HEAD scheitert, soll die Dateiendung verwendet werden
test('it uses filename extension fallback when head fails', function (): void {

    // Simuliert: 404 not found 
    Http::fake([
        'https://files.example.org/data.zip' => Http::response('', 404),
    ]);

    $service = app(SizeFormatServiceTest::class);

    /**
     * HEAD
     * ↓
     * 404
     * ↓
     * Fallback
     * ↓
     * Dateiname analysieren
     * ↓
     * zip erkannt
     */
    $result = $service->inferMetadataFromFileUrl(
        'https://files.example.org/data.zip'
    );

    // Wurde Fallback benutzt?
    expect($result['probe_method'])->toBe('FILENAME_EXTENSION_FALLBACK');
    // Dateiendung korrekt erkannt?
    expect($result['suggestions'][0]['inferred_value'])->toBe('zip');
});

// FTP darf laut Safety Policy nicht verarbeitet werden.
test('it skips unsupported protocols', function (): void {

    $service = app(SizeFormatServiceTest::class);

    $result = $service->extractAndProbe(
        'ftp://example.org/file.zip'
    );

    expect($result[0]['probe_method'])->toBe('SKIP');
    expect($result[0]['skip_reason'])->toBe('unsupported_protocol');
    });

// DOI wird aufgelöst, landet aber auf einer nicht erlaubten Quelle
test('it skips doi redirects to unsupported sources', function (): void {

    // Fake-DOI antwortet
    Http::fake([
        'https://doi.org/*' => Http::response('', 200),
    ]);

    // Service: DOI erkennen-> auflösen-> echte URL bestimmen-> prüfen: beginnt sie mit https://dataservices.gfz-potsdam.de/? Antwort Nein
    $service = app(SizeFormatServiceTest::class);

    $result = $service->extractAndProbe(
        'https://doi.org/10.1234/test'
    );

    // zeigt: URL wurde übersprungen, aber nicht den Grund
    // Beweis für: Service hat die URL nicht weiter verarbeitet, sondern übersprungen
    expect($result[0]['probe_method'])
        ->toBe('SKIP');

    // zeigt: Service hat den richtigen Skip-Grund erkannt 
    // Service hat die URL aus dem richtigen Grund übersprungen
    expect($result[0]['skip_reason'])
        ->toBe('unsupported_source_url');
});

// Landingpage existiert nicht
test('it skips inaccessible urls', function (): void {

    // Jeder Aufruf liefert: 404 not found 
    Http::fake([
        'https://dataservices.gfz-potsdam.de/*' => Http::response('', 404),
    ]);

    // bei 404 liefert false
    $service = app(SizeFormatServiceTest::class);

    $result = $service->extractAndProbe(
        'https://dataservices.gfz-potsdam.de/test'
    );

    // Schaue in das Ergebnis und prüfe, ob probe_method genau den Wert "SKIP" hat
    // Beweis für: der Service hat übersprungen
    expect($result[0]['probe_method'])
        ->toBe('SKIP');

    // prüfe, ob der Grund für das Überspringen genau "landing_page_unreachable" ist
    // der Service hat den richtigen Skip-Grund erkannt
    expect($result[0]['skip_reason'])
        ->toBe('landing_page_unreachable');

});