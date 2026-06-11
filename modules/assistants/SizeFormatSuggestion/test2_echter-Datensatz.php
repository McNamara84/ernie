<?php

declare(strict_types=1);

// Lädt den Composer-Autoloader
// Dadurch können Klassen automatisch gefunden werden, ohne dass du jede Datei einzeln einbinden musst
/**
 * Beispiel für Klassen: 
 * App\Models\Resource
 * App\Services\SizeFormatFileProbeService
 * Illuminate\Support\Facades\Http
 */
require __DIR__ . '/../../../vendor/autoload.php';

// in $app wird die komplette Laravel-App
$app = require_once __DIR__ . '/../../../bootstrap/app.php';

$app->make(
    Illuminate\Contracts\Console\Kernel::class
)->bootstrap();

// importiert die Klasse 
use App\Services\SizeFormatFileProbeService;

// Gibt einfach Text auf der Konsole aus.
echo PHP_EOL;
// Ergebnis:
echo "=== Single Dataset Test ===" . PHP_EOL;
// PHP_EOL bedeutet Zeilenumbruch 
echo PHP_EOL;

// Hier wird dein Service aus dem Laravel Service Container geholt
$service = app(SizeFormatFileProbeService::class);

// Hier beginnt der eigentliche Test
// Der Service bekommt die DOI-URL
// Getestet: 
// Idealfall Doi: https://doi.org/10.5880/WSM.2025.001
// Doi mit mehreren Links bei Files: https://doi.org/10.5880/riesgos.2021.011
// Doi mit Formular zum ausfüllen bei Files: https://doi.org/10.5880/ICDP.5073.001
// Doi mit Ordnern bei Files: https://doi.org/10.5880/GFZ_ORBIT/RSO/L06_G_v01

$result = $service->extractAndProbe(
    'https://doi.org/10.5880/GFZ_ORBIT/RSO/L06_G_v01'
);

// Ergebnis wird in print gespeichert 
print_r($result);