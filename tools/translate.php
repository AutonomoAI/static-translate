#!/usr/bin/env php
<?php

declare(strict_types=1);

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;

const TEXT_NODE = 3;
const LOCALE_PATTERN = '/^[a-z]{2,3}(?:-[A-Z]{2})?$/';

/**
 * Generate localized static HTML pages from JSON translation catalogs.
 *
 * Usage:
 *   php tools/translate.php       # Generate every translations/*.json catalog
 *   php tools/translate.php es    # Generate only translations/es.json
 */

$options = parseArguments(array_slice($argv, 1));
if ($options['help']) {
    echo "Usage: php tools/translate.php [options] [locale ...]\n\n",
        "Host root defaults to the invoking current directory.\n",
        "  --root PATH          Host project root\n",
        "  --catalog-dir PATH   Catalog directory relative to host root (default: translations)\n",
        "  --web-root PATH      Web output root relative to host root (default: public)\n",
        "  -h, --help           Show this help\n";
    exit(0);
}
if (PHP_VERSION_ID < 80400) {
    fail('PHP 8.4 or newer is required.');
}
if (!class_exists(HTMLDocument::class)) {
    fail('The PHP DOM extension is required (PHP 8.4 DOM).');
}

$projectRoot = resolveRoot($options['root']);
$catalogDir = relativeDir($options['catalog-dir'], 'catalog directory');
$webRoot = relativeDir($options['web-root'], 'web root');
$catalogRoot = $projectRoot . ($catalogDir === '.' ? '' : "/{$catalogDir}");
$catalogs = catalogPaths($catalogRoot, $options['locales']);

foreach ($catalogs as $catalogPath) {
    generateCatalog($projectRoot, $catalogPath, $webRoot);
}

/** @return array{root:string,catalog-dir:string,web-root:string,locales:list<string>,help:bool} */
function parseArguments(array $arguments): array
{
    $result = [
        'root' => getcwd() ?: '.',
        'catalog-dir' => 'translations',
        'web-root' => 'public',
        'locales' => [],
        'help' => false,
    ];
    for ($i = 0; $i < count($arguments); $i++) {
        $argument = $arguments[$i];
        if ($argument === '-h' || $argument === '--help') {
            $result['help'] = true;
            continue;
        }
        if (in_array($argument, ['--root', '--catalog-dir', '--web-root'], true)) {
            if (!isset($arguments[$i + 1]) || $arguments[$i + 1] === '' || str_starts_with($arguments[$i + 1], '--')) {
                fail("{$argument} requires a path.");
            }
            $result[substr($argument, 2)] = $arguments[++$i];
            continue;
        }
        if (str_starts_with($argument, '-')) {
            fail("Unknown option '{$argument}'.");
        }
        if (!preg_match(LOCALE_PATTERN, $argument)) {
            fail("Invalid locale '{$argument}'.");
        }
        $result['locales'][] = $argument;
    }
    return $result;
}

function resolveRoot(string $root): string
{
    $path = realpath($root);
    if ($path === false || !is_dir($path)) {
        fail("Host root is not a directory: {$root}");
    }
    return $path;
}

function relativeDir(string $path, string $label): string
{
    if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0") || preg_match('~(^|/)[.][.](/|$)~', $path)) {
        fail("Invalid {$label}: '{$path}'.");
    }
    $segments = array_filter(explode('/', $path), fn (string $segment): bool => $segment !== '' && $segment !== '.');
    return implode('/', $segments) ?: '.';
}

/** @return list<string> */
function catalogPaths(string $catalogRoot, array $locales): array
{
    if ($locales !== []) {
        $paths = [];
        foreach ($locales as $locale) {
            $paths[] = "{$catalogRoot}/{$locale}.json";
        }
        return $paths;
    }

    $paths = glob("{$catalogRoot}/*.json");
    if ($paths === false || $paths === []) {
        fail("No translation catalogs found in {$catalogRoot}.");
    }

    sort($paths);
    return array_values($paths);
}

function generateCatalog(string $projectRoot, string $catalogPath, string $webRoot): void
{
    if (!is_file($catalogPath)) {
        fail("Translation catalog not found: {$catalogPath}");
    }

    try {
        $catalog = json_decode(
            file_get_contents($catalogPath) ?: '',
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    } catch (JsonException $error) {
        fail("Invalid JSON in {$catalogPath}: {$error->getMessage()}");
    }

    if (!is_array($catalog)) {
        fail("Catalog {$catalogPath} must contain a JSON object.");
    }

    $locale = requiredString($catalog, 'locale', $catalogPath);
    if (!preg_match(LOCALE_PATTERN, $locale)) {
        fail("Catalog {$catalogPath} has invalid locale '{$locale}'.");
    }
    $direction = requiredString($catalog, 'direction', $catalogPath);
    if (!in_array($direction, ['ltr', 'rtl'], true)) {
        fail("Catalog {$catalogPath} has invalid direction '{$direction}'.");
    }

    $pages = $catalog['pages'] ?? null;
    if (!is_array($pages) || $pages === []) {
        fail("Catalog {$catalogPath} must contain at least one page.");
    }

    foreach ($pages as $pageNumber => $page) {
        if (!is_array($page)) {
            fail("Page {$pageNumber} in {$catalogPath} must be an object.");
        }

        $sourceRelative = requiredString($page, 'source', $catalogPath);
        $outputRelative = requiredString($page, 'output', $catalogPath);
        $sourcePath = projectPath($projectRoot, $sourceRelative);
        $outputPath = projectPath($projectRoot, $outputRelative);

        $expectedOutputPrefix = ($webRoot === '.' ? "{$locale}/" : "{$webRoot}/{$locale}/");
        if (!str_starts_with($outputRelative, $expectedOutputPrefix)) {
            fail("Output '{$outputRelative}' must be inside {$expectedOutputPrefix}.");
        }
        if (realpath($sourcePath) !== false && realpath($sourcePath) === realpath($outputPath)) {
            fail("Source and output must be different files: '{$sourceRelative}'.");
        }
        if (!is_file($sourcePath)) {
            fail("Source HTML not found: {$sourceRelative}");
        }

        $html = file_get_contents($sourcePath);
        if ($html === false) {
            fail("Could not read source HTML: {$sourceRelative}");
        }

        $document = HTMLDocument::createFromString($html);
        $document->documentElement->setAttribute('lang', $locale);
        $document->documentElement->setAttribute('dir', $direction);

        $replacements = $page['replacements'] ?? null;
        if (!is_array($replacements) || $replacements === []) {
            fail("Page {$sourceRelative} has no replacements.");
        }

        foreach ($replacements as $replacementNumber => $replacement) {
            applyReplacement(
                $document,
                $replacement,
                "{$catalogPath}, page {$sourceRelative}, replacement {$replacementNumber}",
            );
        }

        $generated = $document->saveHtml();
        $generated = preg_replace(
            '/^<!DOCTYPE html>\s*/i',
            "<!DOCTYPE html>\n<!-- Generated by tools/translate.php from {$sourceRelative}; do not edit directly. -->\n",
            $generated,
            1,
        ) ?? $generated;

        $outputDirectory = dirname($outputPath);
        if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
            fail("Could not create output directory: {$outputDirectory}");
        }

        $temporaryPath = tempnam($outputDirectory, '.translate-');
        if ($temporaryPath === false || file_put_contents($temporaryPath, $generated) === false) {
            if ($temporaryPath !== false) {
                @unlink($temporaryPath);
            }
            fail("Could not write generated page: {$outputRelative}");
        }
        if (!chmod($temporaryPath, 0644)) {
            @unlink($temporaryPath);
            fail("Could not set permissions on generated page: {$outputRelative}");
        }
        if (!rename($temporaryPath, $outputPath)) {
            @unlink($temporaryPath);
            fail("Could not replace generated page: {$outputRelative}");
        }

        echo "Generated {$outputRelative} ({$locale}, ", count($replacements), " replacements)\n";
    }
}

function applyReplacement(HTMLDocument $document, mixed $replacement, string $context): void
{
    if (!is_array($replacement)) {
        fail("{$context} must be an object.");
    }

    $selector = requiredString($replacement, 'selector', $context);
    $source = requiredString($replacement, 'source', $context);
    $target = requiredString($replacement, 'target', $context);
    try {
        $elements = $document->querySelectorAll($selector);
    } catch (DOMException $error) {
        fail("{$context}: invalid selector '{$selector}': {$error->getMessage()}");
    }
    if (count($elements) !== 1) {
        fail("{$context}: selector '{$selector}' must match one element; found " . count($elements) . '.');
    }

    $element = $elements->item(0);
    if (!$element instanceof Element) {
        fail("{$context}: selector '{$selector}' did not match an HTML element.");
    }

    $attribute = $replacement['attribute'] ?? null;
    if ($attribute !== null) {
        if (!is_string($attribute) || $attribute === '') {
            fail("{$context}: attribute must be a non-empty string.");
        }
        $current = $element->getAttribute($attribute);
        if ($current !== $source) {
            fail("{$context}: expected {$attribute}='{$source}', found '{$current}'.");
        }
        $element->setAttribute($attribute, $target);
        return;
    }

    $matches = [];
    collectMatchingTextNodes($element, $source, $matches);
    if (count($matches) !== 1) {
        fail("{$context}: expected one text node containing '{$source}', found " . count($matches) . '.');
    }

    $node = $matches[0];
    $value = $node->nodeValue ?? '';
    $start = strpos($value, $source);
    if ($start === false) {
        fail("{$context}: internal text match error for '{$source}'.");
    }

    $node->nodeValue = substr($value, 0, $start)
        . $target
        . substr($value, $start + strlen($source));
}

/** @param list<Node> $matches */
function collectMatchingTextNodes(Node $node, string $source, array &$matches): void
{
    foreach ($node->childNodes as $child) {
        if ($child->nodeType === TEXT_NODE && trim($child->nodeValue ?? '') === $source) {
            $matches[] = $child;
            continue;
        }
        collectMatchingTextNodes($child, $source, $matches);
    }
}

function requiredString(array $data, string $key, string $context): string
{
    $value = $data[$key] ?? null;
    if (!is_string($value) || $value === '') {
        fail("{$context} requires a non-empty '{$key}' string.");
    }
    return $value;
}

function projectPath(string $projectRoot, string $relativePath): string
{
    if ($relativePath === '' || str_starts_with($relativePath, '/') || str_contains($relativePath, '..') || str_contains($relativePath, "\0")) {
        fail("Unsafe project-relative path: '{$relativePath}'.");
    }
    return "{$projectRoot}/{$relativePath}";
}

function fail(string $message): never
{
    fwrite(STDERR, "Translation generation failed: {$message}\n");
    exit(1);
}
