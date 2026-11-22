<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/autoload.php';

use App\Core\App;

App::boot(dirname(__DIR__));

$it = require App::path('lang/it.php');
$en = require App::path('lang/en.php');
$used = [];

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(App::path('.'), FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    if (!str_contains($path, 'views') && !str_contains($path, 'Http')) {
        continue;
    }
    preg_match_all("/__\('([a-z0-9_.]+)'/", (string) file_get_contents($path), $matches);
    foreach ($matches[1] as $key) {
        $used[$key] = true;
    }
}

$missingIt = array_values(array_diff(array_keys($used), array_keys($it)));
$missingEn = array_values(array_diff(array_keys($used), array_keys($en)));

fwrite(STDOUT, 'used=' . count($used) . ' it=' . count($it) . ' en=' . count($en) . PHP_EOL);
fwrite(STDOUT, 'missing IT: ' . implode(', ', $missingIt) . PHP_EOL);
fwrite(STDOUT, 'missing EN: ' . implode(', ', $missingEn) . PHP_EOL);

foreach (['it', 'en'] as $locale) {
    $counts = [];
    foreach (file(App::path('lang/' . $locale . '.php')) ?: [] as $line) {
        if (preg_match("/^\s+'([a-z0-9_.]+)' =>/", $line, $m)) {
            $counts[$m[1]] = ($counts[$m[1]] ?? 0) + 1;
        }
    }
    $dups = array_filter($counts, static fn (int $c): bool => $c > 1);
    fwrite(STDOUT, 'duplicates ' . $locale . ': ' . implode(', ', array_keys($dups)) . PHP_EOL);
}
