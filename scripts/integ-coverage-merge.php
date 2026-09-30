<?php

declare(strict_types=1);

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData;
use SebastianBergmann\CodeCoverage\Driver\Driver;
use SebastianBergmann\CodeCoverage\Filter;
use SebastianBergmann\CodeCoverage\Report\Html\Facade as HtmlReport;
use SebastianBergmann\CodeCoverage\Report\Text as TextReport;
use SebastianBergmann\CodeCoverage\Report\Thresholds;
use SebastianBergmann\CodeCoverage\Test\TestStatus\TestStatus;

require dirname(__DIR__) . '/vendor/autoload.php';

const INTEG_COVERAGE_CONTAINER_ROOT = '/var/www/idp.amtgard.com';

/**
 * @param array<string, array<int|string, int>> $accumulator
 * @param array<string, array<int|string, int>> $chunk
 *
 * @return array<string, array<int, int>>
 */
function integ_coverage_map_path(string $file, string $hostRoot): string
{
    if (str_starts_with($file, INTEG_COVERAGE_CONTAINER_ROOT)) {
        return $hostRoot . substr($file, strlen(INTEG_COVERAGE_CONTAINER_ROOT));
    }

    return $file;
}

/**
 * @param array<string, array<int|string, int>> $accumulator
 * @param array<string, array<int|string, int>> $chunk
 *
 * @return array<string, array<int, int>>
 */
function integ_coverage_merge_chunks(array $accumulator, array $chunk, string $hostRoot): array
{
    foreach ($chunk as $file => $lines) {
        $file = integ_coverage_map_path($file, $hostRoot);
        if (!isset($accumulator[$file])) {
            $accumulator[$file] = [];
        }

        foreach ($lines as $line => $hit) {
            $lineNum = (int) $line;
            $hitVal = (int) $hit;
            if ($hitVal <= 0) {
                continue;
            }

            $previous = $accumulator[$file][$lineNum] ?? 0;
            if ($hitVal > $previous) {
                $accumulator[$file][$lineNum] = $hitVal;
            }
        }
    }

    return $accumulator;
}

/**
 * @param array<string, array<int, int>> $raw
 *
 * @return array<string, array<int, int>>
 */
function integ_coverage_normalize_for_report(array $raw): array
{
    $normalized = [];

    foreach ($raw as $file => $lines) {
        foreach ($lines as $line => $hit) {
            if ((int) $hit > 0) {
                $normalized[$file][(int) $line] = Driver::LINE_EXECUTED;
            }
        }
    }

    return $normalized;
}

final class IntegCoverageMergeDriver extends Driver
{
    public function nameAndVersion(): string
    {
        return 'integ-coverage-merge';
    }

    public function start(): void
    {
    }

    public function stop(): RawCodeCoverageData
    {
        return RawCodeCoverageData::fromXdebugWithoutPathCoverage([]);
    }
}

$root = dirname(__DIR__);
$rawDir = $root . '/build/integ-coverage/raw';
$outDir = $root . '/build/integ-coverage';
$htmlDir = $outDir . '/html';
$textFile = $outDir . '/coverage.txt';

if (!is_dir($rawDir)) {
    fwrite(STDERR, "No raw coverage directory: {$rawDir}\n");
    exit(1);
}

$files = glob($rawDir . '/cov-*.json') ?: [];
if ($files === []) {
    fwrite(STDERR, "No cov-*.json fragments in {$rawDir}\n");
    exit(1);
}

$merged = [];
foreach ($files as $path) {
    $json = file_get_contents($path);
    if ($json === false) {
        continue;
    }

    /** @var array<string, array<int|string, int>> $chunk */
    $chunk = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $merged = integ_coverage_merge_chunks($merged, $chunk, $root);
}

$filter = new Filter();
$srcFiles = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS),
);
foreach ($srcFiles as $fileInfo) {
    if ($fileInfo->isFile() && str_ends_with($fileInfo->getPathname(), '.php')) {
        $filter->includeFile($fileInfo->getPathname());
    }
}

$coverage = new CodeCoverage(new IntegCoverageMergeDriver(), $filter);

$index = 0;
foreach ($files as $path) {
    $json = file_get_contents($path);
    if ($json === false) {
        continue;
    }

    /** @var array<string, array<int|string, int>> $chunk */
    $chunk = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $raw = RawCodeCoverageData::fromXdebugWithoutPathCoverage(
        integ_coverage_normalize_for_report(integ_coverage_merge_chunks([], $chunk, $root)),
    );
    $coverage->append($raw, 'fragment-' . $index++, true, TestStatus::success());
}

if (!is_dir($htmlDir) && !mkdir($htmlDir, 0777, true) && !is_dir($htmlDir)) {
    fwrite(STDERR, "Could not create {$htmlDir}\n");
    exit(1);
}

(new HtmlReport('Amtgard IDP integ coverage'))->process($coverage, $htmlDir);

$text = (new TextReport(Thresholds::default(), false, true))->process($coverage, false);
file_put_contents($textFile, $text);

echo trim($text) . PHP_EOL;
echo PHP_EOL . "HTML report: {$htmlDir}/index.html" . PHP_EOL;
echo "Text summary: {$textFile}" . PHP_EOL;
echo 'Raw fragments: ' . count($files) . PHP_EOL;
