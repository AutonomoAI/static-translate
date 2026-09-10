<?php

declare(strict_types=1);

// Run isolated command-line scenarios without a test framework.
$utility = dirname(__DIR__);
$temporaryRoot = sys_get_temp_dir() . '/static translate tests ' . bin2hex(random_bytes(6));
mkdir($temporaryRoot, 0775, true);
$passed = 0;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array{int, string} */
function command(array $arguments, string $cwd): array
{
    // Redirect stderr to stdout to avoid deadlocks between two output pipes.
    $process = proc_open(
        $arguments,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes,
        $cwd,
    );
    check(is_resource($process), 'Could not start command.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return [proc_close($process), $output];
}

/** @return array{int, string} */
function translate(string $host, array $arguments = []): array
{
    global $utility;
    return command([PHP_BINARY, "{$utility}/tools/translate.php", ...$arguments], $host);
}

function succeeded(array $result): string
{
    [$status, $output] = $result;
    check($status === 0, "Command failed ({$status}): {$output}");
    return $output;
}

function failed(array $result, string $diagnostic): void
{
    [$status, $output] = $result;
    check($status !== 0, "Command unexpectedly succeeded: {$output}");
    check(str_contains($output, $diagnostic), "Expected '{$diagnostic}', got: {$output}");
}

function scenario(string $name, callable $test): void
{
    global $passed;
    $test();
    $passed++;
    echo "PASS {$name}\n";
}

function catalog(string $locale = 'es', string $source = 'public/index.html', ?string $output = null): array
{
    return [
        'locale' => $locale,
        'direction' => $locale === 'ar' ? 'rtl' : 'ltr',
        'pages' => [[
            'source' => $source,
            'output' => $output ?? "public/{$locale}/index.html",
            'replacements' => [
                ['selector' => 'h1', 'source' => 'Welcome', 'target' => $locale === 'ar' ? 'مرحبًا' : 'Bienvenido'],
                ['selector' => '.inline', 'source' => 'Hello', 'target' => 'Hola'],
                ['selector' => 'input', 'attribute' => 'placeholder', 'source' => 'Search', 'target' => 'Buscar'],
            ],
        ]],
    ];
}

function writeCatalog(string $host, array $catalog, string $directory = 'translations', string $filename = 'es'): void
{
    $path = "{$host}/{$directory}";
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
    file_put_contents(
        "{$path}/{$filename}.json",
        json_encode($catalog, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n",
    );
}

function fixture(string $name): string
{
    global $temporaryRoot;
    $host = "{$temporaryRoot}/{$name}";
    mkdir("{$host}/public", 0775, true);
    file_put_contents(
        "{$host}/public/index.html",
        '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"></head><body>'
        . '<h1>Welcome</h1><p class="inline">Hello <strong>world</strong></p>'
        . '<input placeholder="Search"></body></html>',
    );
    writeCatalog($host, catalog());
    return $host;
}

function removeTree(string $root): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($root);
}

try {
    scenario('help without catalogs', function () use ($temporaryRoot): void {
        check(str_contains(succeeded(translate($temporaryRoot, ['--help'])), 'Usage:'), 'Missing usage.');
    });

    scenario('nested utility and build wrapper with spaces in paths', function () use ($utility): void {
        $host = fixture('nested host');
        $nested = "{$host}/tools/translator copy";
        mkdir("{$nested}/tools", 0775, true);
        copy("{$utility}/tools/translate.php", "{$nested}/tools/translate.php");
        copy("{$utility}/build.sh", "{$nested}/build.sh");
        chmod("{$nested}/build.sh", 0755);
        succeeded(command(["{$nested}/build.sh", 'es'], $host));
        check(is_file("{$host}/public/es/index.html"), 'Wrapper used utility directory as host.');
    });

    scenario('selected locales and all locales', function (): void {
        $host = fixture('selection');
        writeCatalog($host, catalog('ar'), filename: 'ar');
        succeeded(translate($host, ['es']));
        check(!file_exists("{$host}/public/ar/index.html"), 'Selected build generated Arabic.');
        succeeded(translate($host));
        $ar = file_get_contents("{$host}/public/ar/index.html");
        check(str_contains($ar, 'lang="ar"') && str_contains($ar, 'dir="rtl"'), 'Missing Arabic document metadata.');
        check(str_contains($ar, 'مرحبًا'), 'Arabic text was not preserved.');
    });

    scenario('explicit root from an unrelated directory', function () use ($temporaryRoot): void {
        $host = fixture('explicit root');
        succeeded(translate($temporaryRoot, ['--root', $host, 'es']));
        check(is_file("{$host}/public/es/index.html"), 'Explicit root was ignored.');
    });

    scenario('custom catalog directory selected and all', function (): void {
        $host = fixture('catalog options');
        unlink("{$host}/translations/es.json");
        writeCatalog($host, catalog(), 'catalog dir');
        writeCatalog($host, catalog('ar'), 'catalog dir', 'ar');
        succeeded(translate($host, ['--catalog-dir', 'catalog dir', 'es']));
        check(!file_exists("{$host}/public/ar/index.html"), 'Custom selected build generated Arabic.');
        succeeded(translate($host, ['--catalog-dir', './catalog dir']));
        check(is_file("{$host}/public/ar/index.html"), 'Custom all build missed Arabic.');
    });

    scenario('custom web root', function (): void {
        $host = fixture('web options');
        writeCatalog($host, catalog(output: 'www/es/index.html'));
        succeeded(translate($host, ['--web-root', './www', 'es']));
        check(is_file("{$host}/www/es/index.html"), 'Custom web root failed.');
    });

    scenario('repository root as web root', function (): void {
        $host = fixture('root web');
        rename("{$host}/public/index.html", "{$host}/index.html");
        writeCatalog($host, catalog(source: 'index.html', output: 'es/index.html'));
        succeeded(translate($host, ['--web-root', '.', 'es']));
        check(is_file("{$host}/es/index.html"), 'Root web output missing.');
    });

    scenario('text, attributes, escaping, whitespace and inline markup', function (): void {
        $host = fixture('escaping');
        $catalog = catalog();
        $catalog['pages'][0]['replacements'][0]['target'] = 'Bienvenido <amigo> & familia';
        $catalog['pages'][0]['replacements'][2]['target'] = 'Buscar "algo" & más';
        writeCatalog($host, $catalog);
        succeeded(translate($host, ['es']));
        $html = file_get_contents("{$host}/public/es/index.html");
        $document = Dom\HTMLDocument::createFromString($html);
        check($document->querySelector('h1')->textContent === 'Bienvenido <amigo> & familia', 'Text changed.');
        check($document->querySelector('h1')->childElementCount === 0, 'Target text became HTML.');
        check($document->querySelector('input')->getAttribute('placeholder') === 'Buscar "algo" & más', 'Attribute changed.');
        check(str_contains($html, 'Hola <strong>world</strong>'), 'Inline markup or whitespace changed.');
        check(str_contains($html, '<!-- Generated by tools/translate.php'), 'Generated marker missing.');
    });

    $failureCases = [
        'blank target' => [
            function (array &$catalog): void { $catalog['pages'][0]['replacements'][0]['target'] = ''; },
            "requires a non-empty 'target'",
        ],
        'source drift' => [
            function (array &$catalog): void { $catalog['pages'][0]['replacements'][0]['source'] = 'Changed'; },
            'expected one text node',
        ],
        'attribute drift' => [
            function (array &$catalog): void { $catalog['pages'][0]['replacements'][2]['source'] = 'Changed'; },
            'expected placeholder',
        ],
        'missing selector' => [
            function (array &$catalog): void { $catalog['pages'][0]['replacements'][0]['selector'] = '.missing'; },
            'found 0',
        ],
        'nonunique selector' => [
            function (array &$catalog): void { $catalog['pages'][0]['replacements'][0]['selector'] = 'h1, input'; },
            'found 2',
        ],
        'invalid selector syntax' => [
            function (array &$catalog): void { $catalog['pages'][0]['replacements'][0]['selector'] = '['; },
            'invalid selector',
        ],
        'invalid locale metadata' => [
            function (array &$catalog): void { $catalog['locale'] = '../ar'; },
            'invalid locale',
        ],
        'invalid direction' => [
            function (array &$catalog): void { $catalog['direction'] = 'sideways'; },
            'invalid direction',
        ],
        'unsafe source path' => [
            function (array &$catalog): void { $catalog['pages'][0]['source'] = '../index.html'; },
            'Unsafe project-relative path',
        ],
        'unsafe output path' => [
            function (array &$catalog): void { $catalog['pages'][0]['output'] = 'public/es/../../index.html'; },
            'Unsafe project-relative path',
        ],
        'output outside locale directory' => [
            function (array &$catalog): void { $catalog['pages'][0]['output'] = 'public/index.html'; },
            'must be inside public/es/',
        ],
    ];
    foreach ($failureCases as $name => [$change, $diagnostic]) {
        scenario("reject {$name} and preserve existing output", function () use ($name, $change, $diagnostic): void {
            $host = fixture($name);
            succeeded(translate($host, ['es']));
            $before = file_get_contents("{$host}/public/es/index.html");
            $sourceBefore = file_get_contents("{$host}/public/index.html");
            $catalog = catalog();
            $change($catalog);
            writeCatalog($host, $catalog);
            failed(translate($host, ['es']), $diagnostic);
            check(file_get_contents("{$host}/public/es/index.html") === $before, 'Failed build replaced output.');
            check(file_get_contents("{$host}/public/index.html") === $sourceBefore, 'Failed build changed source.');
        });
    }

    foreach (['invalid JSON' => ['{', 'Invalid JSON'], 'scalar JSON' => ['42', 'must contain a JSON object']] as $name => [$json, $diagnostic]) {
        scenario("reject {$name}", function () use ($name, $json, $diagnostic): void {
            $host = fixture($name);
            file_put_contents("{$host}/translations/es.json", $json);
            failed(translate($host, ['es']), $diagnostic);
            check(!file_exists("{$host}/public/es/index.html"), 'Invalid catalog produced output.');
        });
    }

    scenario('reject overwriting source including a path alias', function (): void {
        $host = fixture('overwrite');
        mkdir("{$host}/public/es", 0775, true);
        rename("{$host}/public/index.html", "{$host}/public/es/index.html");
        $source = file_get_contents("{$host}/public/es/index.html");
        writeCatalog($host, catalog(source: 'public/es/index.html', output: 'public/es/./index.html'));
        failed(translate($host, ['es']), 'Source and output must be different');
        check(file_get_contents("{$host}/public/es/index.html") === $source, 'Source was overwritten.');
    });

    scenario('CLI errors have actionable diagnostics', function (): void {
        $host = fixture('cli errors');
        failed(translate($host, ['--root']), '--root requires a path');
        failed(translate($host, ['--root', '--web-root', '.']), '--root requires a path');
        failed(translate($host, ['--unknown']), 'Unknown option');
        failed(translate($host, ['../ar']), 'Invalid locale');
        failed(translate($host, ['fr']), 'Translation catalog not found');
        failed(translate($host, ['--catalog-dir', '..']), 'Invalid catalog directory');
    });

    echo "\n{$passed} scenarios passed.\n";
} finally {
    removeTree($temporaryRoot);
}
