<?php
error_reporting(error_reporting() & ~(E_WARNING | E_CORE_WARNING | E_COMPILE_WARNING | E_USER_WARNING | E_DEPRECATED | E_USER_DEPRECATED));
class LspHelper
{
public static function relativePath($path)
{
if (!str_contains($path, base_path())) {
return (string) $path;
}

return ltrim(str_replace(base_path(), '', realpath($path) ?: $path), DIRECTORY_SEPARATOR);
}

public static function isVendor($path)
{
return str_contains($path, base_path('vendor'));
}

public static function propertyDefault(ReflectionProperty $property, ?ReflectionParameter $parameter = null): array
{
if ($property->hasDefaultValue()) {
return ['default' => $property->getDefaultValue()];
}

if ($parameter?->isDefaultValueAvailable()) {
return ['default' => $parameter->getDefaultValue()];
}

return [];
}

public static function formatDefaultValue(mixed $value): mixed
{
return match (true) {
is_array($value) => 'array(...)',
$value instanceof UnitEnum => get_class($value) . '::' . $value->name,
$value instanceof Closure => 'Closure',
is_object($value) => get_class($value),
is_string($value) => var_export($value, true),
is_null($value) => 'null',
is_bool($value) => $value ? 'true' : 'false',
default => $value,
};
}
}

use Pest\Expectation;
use Pest\TestSuite;

$pest = new class
{
public function __construct()
{
if ($this->isInstalled()) {
$this->boot();
}
}

public function isInstalled(): bool
{
return class_exists(TestSuite::class);
}

protected function boot(): void
{
require_once base_path('vendor/pestphp/pest/overrides/Runner/TestSuiteLoader.php');

TestSuite::getInstance(base_path(), 'tests');

if (file_exists($pestFile = base_path('tests/Pest.php'))) {
require_once $pestFile;
}
}

public function config(): ?array
{
if (!$this->isInstalled()) {
return null;
}

return [
'uses' => $this->uses(),
'expectations' => $this->expectations(),
];
}

protected function uses(): array
{
if (is_null($instance = TestSuite::getInstance())) {
return [];
}

$reflection = new ReflectionProperty($instance->tests, 'uses');
$uses = $reflection->getValue($instance->tests);

return collect($uses)->map(function (array $use, string $path) {
[$classOrTraits] = $use;

return [
'path' => LspHelper::relativePath($path),
'classes' => array_values(array_filter($classOrTraits, fn ($c) => class_exists($c))),
'traits' => array_values(array_filter($classOrTraits, fn ($c) => trait_exists($c))),
];
})->values()->all();
}

protected function expectations(): array
{
$reflection = new ReflectionProperty(Expectation::class, 'extends');
$extends = $reflection->getValue();

return collect($extends)->map(function (Closure $closure, string $name) {
$parameters = collect((new ReflectionFunction($closure))->getParameters())
->map(function (ReflectionParameter $param) {
$type = $param->hasType() ? $param->getType() . ' ' : '';

$default = $param->isOptional() && $param->isDefaultValueAvailable()
? ' = ' . var_export($param->getDefaultValue(), true)
: '';

return $type . '$' . $param->getName() . $default;
})
->join(', ');

return compact('name', 'parameters');
})->values()->all();
}
};

echo json_encode($pest->config());
