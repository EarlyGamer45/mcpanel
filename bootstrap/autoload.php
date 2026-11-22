<?php

declare(strict_types=1);

/**
 * Classmap autoloader.
 *
 * The platform intentionally ships without a vendor/ tree: Ubuntu 26.04 provides
 * PHP 8.5 and every extension we need, so there is no third-party runtime
 * dependency to install, patch or audit. Loading is done with an optimised
 * classmap (the same strategy as `composer dump-autoload -o`), rebuilt whenever
 * a source file changes and cached in storage/cache.
 */

$basePath = dirname(__DIR__);
$cacheFile = $basePath . '/storage/cache/classmap.php';
$roots = [$basePath . '/src', $basePath . '/database', $basePath . '/tests', $basePath . '/license-server/src'];

$scan = static function (array $roots): array {
    $map = [];
    foreach ($roots as $root) {
        if (!is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            $tokens = token_get_all($code);
            $namespace = '';
            $count = count($tokens);
            for ($i = 0; $i < $count; $i++) {
                $token = $tokens[$i];
                if (!is_array($token)) {
                    continue;
                }
                if ($token[0] === T_NAMESPACE) {
                    $namespace = '';
                    for ($j = $i + 1; $j < $count; $j++) {
                        $part = $tokens[$j];
                        if (is_array($part) && in_array($part[0], [T_STRING, T_NAME_QUALIFIED], true)) {
                            $namespace = $part[1];
                        } elseif (is_string($part) && ($part === ';' || $part === '{')) {
                            break;
                        }
                    }
                }
                if (in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                    // Skip anonymous classes and "::class" / "Foo::class" usages.
                    $prev = $i - 1;
                    while ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) {
                        $prev--;
                    }
                    if ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_DOUBLE_COLON) {
                        continue;
                    }
                    if ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_NEW) {
                        continue;
                    }
                    for ($j = $i + 1; $j < $count; $j++) {
                        if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                            $class = $tokens[$j][1];
                            $fqcn = $namespace === '' ? $class : $namespace . '\\' . $class;
                            $map[$fqcn] = $file->getPathname();
                            break;
                        }
                    }
                }
            }
        }
    }

    return $map;
};

$map = null;
$fingerprint = '';
foreach ($roots as $root) {
    if (!is_dir($root)) {
        continue;
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $fingerprint .= $file->getPathname() . ':' . $file->getMTime() . ':' . $file->getSize() . '|';
        }
    }
}
$signature = hash('sha256', $fingerprint);
if (is_file($cacheFile)) {
    $cached = require $cacheFile;
    if (is_array($cached) && ($cached['signature'] ?? null) === $signature) {
        $map = $cached['map'];
    }
}

if (!is_array($map)) {
    $map = $scan($roots);
    $dir = dirname($cacheFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        @file_put_contents($cacheFile, '<?php return ' . var_export(['signature' => $signature, 'map' => $map], true) . ';');
    }
}

spl_autoload_register(static function (string $class) use ($map): void {
    if (isset($map[$class])) {
        require_once $map[$class];
    }
});

require_once $basePath . '/src/helpers.php';
