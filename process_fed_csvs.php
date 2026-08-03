<?php

declare(strict_types=1);

ini_set('memory_limit', '-1');
set_time_limit(0);

const FED_SUFFIX = ' -FED';

main($argv);

function main(array $argv): void
{
    $options = parseArguments($argv);
    $timestamp = date('Ymd_Hi');

    ensureDirectory($options['output-dir']);

    $fedData = buildFedLookup($options['fed']);

    $namesetResult = processNamesetFile(
        $fedData['lookup'],
        $options['nameset'],
        $options['output-dir'],
        $timestamp
    );

    $tagResult = processTagFile(
        $fedData['lookup'],
        $options['tag'],
        $options['output-dir'],
        $timestamp
    );

    $interfaceResults = processInterfaceFile(
        $fedData['lookup'],
        $options['interface'],
        $options['output-dir'],
        $timestamp
    );

    echo "Nameset matches: {$namesetResult['count']} -> {$namesetResult['path']}" . PHP_EOL;
    echo "Tag matches (first 2 columns): {$tagResult['first_two_count']} -> {$tagResult['first_two_path']}" . PHP_EOL;
    echo "Tag matches (full rows): {$tagResult['full_count']} -> {$tagResult['full_path']}" . PHP_EOL;

    foreach ($interfaceResults as $result) {
        echo "Interface matches [{$result['interface_name']}]: {$result['match_count']} -> {$result['path']}" . PHP_EOL;
    }
}

function parseArguments(array $argv): array
{
    $allowedOptions = ['fed', 'nameset', 'tag', 'interface', 'output-dir'];
    $parsed = [];
    $positionals = [];

    for ($index = 1, $count = count($argv); $index < $count; $index++) {
        $argument = $argv[$index];

        if ($argument === '--help') {
            usageAndExit(0);
        }

        if (!str_starts_with($argument, '--')) {
            $positionals[] = $argument;
            continue;
        }

        $name = $argument;
        $value = null;

        if (str_contains($argument, '=')) {
            [$name, $value] = explode('=', $argument, 2);
        }

        $name = substr($name, 2);
        if (!in_array($name, $allowedOptions, true)) {
            fwrite(STDERR, "Unknown option --{$name}" . PHP_EOL);
            usageAndExit(1);
        }

        if ($value === null) {
            $index++;
            if ($index >= $count) {
                fwrite(STDERR, "Missing value for --{$name}" . PHP_EOL);
                usageAndExit(1);
            }

            $value = $argv[$index];
        }

        $parsed[$name] = $value;
    }

    $options = [
        'fed' => $parsed['fed'] ?? $positionals[0] ?? null,
        'nameset' => $parsed['nameset'] ?? $positionals[1] ?? null,
        'tag' => $parsed['tag'] ?? $positionals[2] ?? null,
        'interface' => $parsed['interface'] ?? $positionals[3] ?? null,
        'output-dir' => $parsed['output-dir'] ?? $positionals[4] ?? getcwd() . DIRECTORY_SEPARATOR . 'output',
    ];

    foreach (['fed', 'nameset', 'tag', 'interface'] as $requiredOption) {
        if (!is_string($options[$requiredOption]) || trim($options[$requiredOption]) === '') {
            fwrite(STDERR, "Missing required option --{$requiredOption}" . PHP_EOL);
            usageAndExit(1);
        }

        $options[$requiredOption] = trim($options[$requiredOption]);
        assertReadableFile($options[$requiredOption], $requiredOption);
    }

    if (!is_string($options['output-dir']) || trim($options['output-dir']) === '') {
        fwrite(STDERR, "The output directory must not be empty." . PHP_EOL);
        exit(1);
    }

    $options['output-dir'] = rtrim(trim($options['output-dir']), DIRECTORY_SEPARATOR);

    return $options;
}

function usageAndExit(int $exitCode): void
{
    $scriptName = basename(__FILE__);
    $usage = <<<USAGE
Usage:
  php {$scriptName} --fed=/path/to/fed.csv --nameset=/path/to/nameset.csv --tag=/path/to/tag.csv --interface=/path/to/interface.csv [--output-dir=/path/to/output]

Positional arguments are also supported in this order:
  php {$scriptName} /path/to/fed.csv /path/to/nameset.csv /path/to/tag.csv /path/to/interface.csv [/path/to/output]

USAGE;

    $stream = $exitCode === 0 ? STDOUT : STDERR;
    fwrite($stream, $usage);
    exit($exitCode);
}

function assertReadableFile(string $path, string $label): void
{
    if (!is_file($path) || !is_readable($path)) {
        fwrite(STDERR, "The {$label} file is not readable: {$path}" . PHP_EOL);
        exit(1);
    }
}

function ensureDirectory(string $path): void
{
    if (is_dir($path)) {
        return;
    }

    if (!mkdir($path, 0777, true) && !is_dir($path)) {
        fwrite(STDERR, "Unable to create output directory: {$path}" . PHP_EOL);
        exit(1);
    }
}

function buildFedLookup(string $fedPath): array
{
    $handle = openCsvForRead($fedPath);
    $header = readCsvHeader($handle, $fedPath);
    $headerMap = buildHeaderMap($header);

    $stuIndex = getRequiredHeaderIndex($headerMap, 'STU System Name', $fedPath);
    $newStuIndex = getRequiredHeaderIndex($headerMap, 'New STU System Name', $fedPath);

    $lookup = [];
    $rowNumber = 1;
    $duplicateCount = 0;
    $conflictingNewNameCount = 0;

    while (($row = fgetcsv($handle)) !== false) {
        $rowNumber++;
        $stuSystemName = getRowValue($row, $stuIndex);
        $normalizedStu = normalizeValue($stuSystemName);

        if ($normalizedStu === '') {
            continue;
        }

        $newStuSystemName = trim(getRowValue($row, $newStuIndex));

        if (!isset($lookup[$normalizedStu])) {
            $lookup[$normalizedStu] = [
                'stu_system_name' => trim($stuSystemName),
                'new_stu_system_name' => $newStuSystemName,
                'row_number' => $rowNumber,
            ];
            continue;
        }

        $duplicateCount++;
        if (
            $lookup[$normalizedStu]['new_stu_system_name'] !== $newStuSystemName
            && $newStuSystemName !== ''
        ) {
            $conflictingNewNameCount++;
        }
    }

    fclose($handle);

    if ($duplicateCount > 0) {
        fwrite(
            STDERR,
            "Warning: encountered {$duplicateCount} duplicate STU System Name value(s) in the fed file; using the first occurrence for matching." . PHP_EOL
        );
    }

    if ($conflictingNewNameCount > 0) {
        fwrite(
            STDERR,
            "Warning: {$conflictingNewNameCount} duplicate fed mapping(s) contained different New STU System Name values; the first value was kept." . PHP_EOL
        );
    }

    return ['lookup' => $lookup];
}

function processNamesetFile(array $fedLookup, string $namesetPath, string $outputDir, string $timestamp): array
{
    $handle = openCsvForRead($namesetPath);
    $header = readCsvHeader($handle, $namesetPath);
    $headerMap = buildHeaderMap($header);

    $portNameIndex = getRequiredHeaderIndex($headerMap, 'Port Name', $namesetPath);
    $suffixColumnNames = [
        'Global',
        'Generic',
        'Remote Local',
        'ALIAS-DNF',
        'Hardware Loc',
        'TOPS',
        'HP',
    ];

    $suffixColumnIndexes = [];
    foreach ($suffixColumnNames as $columnName) {
        $normalized = normalizeHeaderName($columnName);
        if (isset($headerMap[$normalized])) {
            $suffixColumnIndexes[] = $headerMap[$normalized];
        }
    }

    $outputPath = buildOutputPath($outputDir, 'nameset_matches', $timestamp);
    $outputHandle = openCsvForWrite($outputPath);
    fputcsv($outputHandle, $header);

    $count = 0;
    while (($row = fgetcsv($handle)) !== false) {
        $normalizedPortName = normalizeValue(getRowValue($row, $portNameIndex));
        if ($normalizedPortName === '' || !isset($fedLookup[$normalizedPortName])) {
            continue;
        }

        if (shouldAppendFedSuffix($row, $suffixColumnIndexes)) {
            $row[$portNameIndex] = appendFedSuffix(getRowValue($row, $portNameIndex));
        }

        fputcsv($outputHandle, $row);
        $count++;
    }

    fclose($handle);
    fclose($outputHandle);

    return ['count' => $count, 'path' => $outputPath];
}

function processTagFile(array $fedLookup, string $tagPath, string $outputDir, string $timestamp): array
{
    $handle = openCsvForRead($tagPath);
    $header = readCsvHeader($handle, $tagPath);
    $headerMap = buildHeaderMap($header);

    $nameSystemIndex = getRequiredHeaderIndex($headerMap, 'NAME (System)', $tagPath);

    $firstTwoPath = buildOutputPath($outputDir, 'tag_matches_first_two_columns', $timestamp);
    $fullPath = buildOutputPath($outputDir, 'tag_matches_full_rows', $timestamp);

    $firstTwoHandle = openCsvForWrite($firstTwoPath);
    $fullHandle = openCsvForWrite($fullPath);

    $firstTwoHeader = array_slice($header, 0, min(2, count($header)));
    fputcsv($firstTwoHandle, $firstTwoHeader);
    fputcsv($fullHandle, $header);

    $firstTwoCount = 0;
    $fullCount = 0;

    while (($row = fgetcsv($handle)) !== false) {
        $normalizedNameSystem = normalizeValue(getRowValue($row, $nameSystemIndex));
        if ($normalizedNameSystem === '' || !isset($fedLookup[$normalizedNameSystem])) {
            continue;
        }

        $firstTwoRow = array_slice($row, 0, count($firstTwoHeader));
        fputcsv($firstTwoHandle, $firstTwoRow);
        $firstTwoCount++;

        $fullRow = $row;
        $fullRow[$nameSystemIndex] = $fedLookup[$normalizedNameSystem]['stu_system_name'];
        fputcsv($fullHandle, $fullRow);
        $fullCount++;
    }

    fclose($handle);
    fclose($firstTwoHandle);
    fclose($fullHandle);

    return [
        'first_two_count' => $firstTwoCount,
        'first_two_path' => $firstTwoPath,
        'full_count' => $fullCount,
        'full_path' => $fullPath,
    ];
}

function processInterfaceFile(array $fedLookup, string $interfacePath, string $outputDir, string $timestamp): array
{
    $handle = openCsvForRead($interfacePath);
    $header = readCsvHeader($handle, $interfacePath);
    $headerMap = buildHeaderMap($header);

    $interfaceNameIndex = getRequiredHeaderIndex($headerMap, 'Interface Name', $interfacePath);
    $portSystemNameIndex = getRequiredHeaderIndex($headerMap, 'Port System Name', $interfacePath);

    $writers = [];

    while (($row = fgetcsv($handle)) !== false) {
        $interfaceName = trim(getRowValue($row, $interfaceNameIndex));
        $interfaceKey = normalizeValue($interfaceName);

        if (!isset($writers[$interfaceKey])) {
            $displayName = $interfaceName !== '' ? $interfaceName : 'Unnamed Interface';
            $path = buildOutputPath(
                $outputDir,
                'interface_' . sanitizeFilenameComponent($displayName),
                $timestamp
            );

            $writerHandle = openCsvForWrite($path);
            fputcsv($writerHandle, $header);

            $writers[$interfaceKey] = [
                'handle' => $writerHandle,
                'path' => $path,
                'interface_name' => $displayName,
                'match_count' => 0,
            ];
        }

        $normalizedPortSystemName = normalizeValue(getRowValue($row, $portSystemNameIndex));
        if ($normalizedPortSystemName !== '' && isset($fedLookup[$normalizedPortSystemName])) {
            $row[$portSystemNameIndex] = $fedLookup[$normalizedPortSystemName]['new_stu_system_name'];
            $writers[$interfaceKey]['match_count']++;
        }

        fputcsv($writers[$interfaceKey]['handle'], $row);
    }

    fclose($handle);

    $results = [];
    foreach ($writers as $writer) {
        fclose($writer['handle']);
        unset($writer['handle']);
        $results[] = $writer;
    }

    usort(
        $results,
        static fn (array $left, array $right): int => strcasecmp($left['interface_name'], $right['interface_name'])
    );

    return $results;
}

function openCsvForRead(string $path)
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        fwrite(STDERR, "Unable to open CSV file for reading: {$path}" . PHP_EOL);
        exit(1);
    }

    return $handle;
}

function openCsvForWrite(string $path)
{
    $handle = fopen($path, 'wb');
    if ($handle === false) {
        fwrite(STDERR, "Unable to open CSV file for writing: {$path}" . PHP_EOL);
        exit(1);
    }

    return $handle;
}

function readCsvHeader($handle, string $path): array
{
    $header = fgetcsv($handle);
    if ($header === false) {
        fwrite(STDERR, "CSV file is empty or unreadable: {$path}" . PHP_EOL);
        exit(1);
    }

    if (isset($header[0])) {
        $header[0] = removeBom((string) $header[0]);
    }

    return $header;
}

function buildHeaderMap(array $header): array
{
    $headerMap = [];
    foreach ($header as $index => $columnName) {
        $headerMap[normalizeHeaderName((string) $columnName)] = $index;
    }

    return $headerMap;
}

function getRequiredHeaderIndex(array $headerMap, string $columnName, string $path): int
{
    $normalized = normalizeHeaderName($columnName);
    if (!isset($headerMap[$normalized])) {
        fwrite(STDERR, "Missing required column \"{$columnName}\" in {$path}" . PHP_EOL);
        exit(1);
    }

    return $headerMap[$normalized];
}

function getRowValue(array $row, int $index): string
{
    return isset($row[$index]) ? (string) $row[$index] : '';
}

function normalizeHeaderName(string $value): string
{
    $value = removeBom($value);
    $value = trim($value);
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return strtolower($value);
}

function normalizeValue(string $value): string
{
    return strtolower(trim(removeBom($value)));
}

function removeBom(string $value): string
{
    return preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
}

function shouldAppendFedSuffix(array $row, array $suffixColumnIndexes): bool
{
    foreach ($suffixColumnIndexes as $columnIndex) {
        if (trim(getRowValue($row, $columnIndex)) !== '') {
            return true;
        }
    }

    return false;
}

function appendFedSuffix(string $value): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return FED_SUFFIX;
    }

    if (preg_match('/\s+-fed$/i', $trimmed) === 1) {
        return $trimmed;
    }

    return $trimmed . FED_SUFFIX;
}

function buildOutputPath(string $outputDir, string $baseName, string $timestamp): string
{
    return $outputDir . DIRECTORY_SEPARATOR . $baseName . '_' . $timestamp . '.csv';
}

function sanitizeFilenameComponent(string $value): string
{
    $sanitized = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($value)) ?? 'unnamed-interface';
    $sanitized = trim($sanitized, '._-');

    return $sanitized !== '' ? $sanitized : 'unnamed-interface';
}
