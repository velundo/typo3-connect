<?php

declare(strict_types=1);

/*
 * Prüfstand ohne TYPO3-Bootstrap: lädt die Klassen der Erweiterung, bei Bedarf einen TestCase-Ersatz,
 * und führt alle Prüfungen in Tests/Unit aus.
 *
 *   php Tests/smoke.php            (lokal, ohne Vendor)
 *   vendor/bin/phpunit Tests/Unit  (in einer Installation mit PHPUnit)
 */

$root = dirname(__DIR__);

spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'Velundo\\Connect\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = $root . '/Classes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

foreach ([$root . '/vendor/autoload.php', dirname($root, 2) . '/vendor/autoload.php', dirname($root, 3) . '/vendor/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;
        break;
    }
}
if (!class_exists(\PHPUnit\Framework\TestCase::class)) {
    require_once __DIR__ . '/Support/TestCaseShim.php';
}

$files = glob($root . '/Tests/Unit/*Test.php') ?: [];
sort($files);
$passed = 0;
$failed = 0;
foreach ($files as $file) {
    require_once $file;
    $class = 'Velundo\\Connect\\Tests\\Unit\\' . basename($file, '.php');
    if (!class_exists($class)) {
        echo "FEHLT  Klasse $class in $file\n";
        $failed++;
        continue;
    }
    $reflection = new ReflectionClass($class);
    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (!str_starts_with($method->getName(), 'test')) {
            continue;
        }
        $name = $reflection->getShortName() . '::' . $method->getName();
        try {
            $instance = $reflection->newInstance();
            if (method_exists($instance, 'runShim')) {
                $instance->runShim($method->getName());
            } else {
                // Echtes PHPUnit vorhanden: Methode direkt aufrufen (setUp über Reflection)
                $setUp = $reflection->getMethod('setUp');
                $setUp->setAccessible(true);
                $setUp->invoke($instance);
                $instance->{$method->getName()}();
            }
            $passed++;
        } catch (\Throwable $e) {
            $failed++;
            echo "FEHLER $name: " . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
        }
    }
}
echo sprintf("%d Prüfungen bestanden, %d fehlgeschlagen (PHP %s)\n", $passed, $failed, PHP_VERSION);
exit($failed === 0 ? 0 : 1);
