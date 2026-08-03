<?php

declare(strict_types=1);

ini_set('memory_limit', '-1');
set_time_limit(0);

const FED_SUFFIX = ' FED';
const CSV_SEPARATOR = ',';
const CSV_ENCLOSURE = '"';
const CSV_ESCAPE = '\\';

main($argv);

function main(array $argv): void
{
    $options = parseArguments($argv);
    $timestamp = date('Ymd_Hi');

    ensureDirectory($options['output-dir']);

    $fedData = buildFedLookup($options['fed']);

    $namesetResult = processNamesetFile(
        $fedData['rows'],
        $options['nameset'],
        $options['output-dir'],
        $timestamp
    );

    $tagResult = processTagFile(
        $fedData['rows'],
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

    echo "Nameset matches: {$namesetResult['match_count']} ({$namesetResult['row_count']} rows written) -> {$namesetResult['path']}" . PHP_EOL;
    echo "Tag matches: {$tagResult['match_count']} ({$tagResult['row_count']} rows written) -> {$tagResult['path']}" . PHP_EOL;

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
    $rows = [];
    $rowNumber = 1;
    $duplicateCount = 0;
    $conflictingNewNameCount = 0;

    while (($row = readCsvRow($handle)) !== false) {
        $rowNumber++;
        $stuSystemName = getRowValue($row, $stuIndex);
        $normalizedStu = normalizeValue($stuSystemName);

        if ($normalizedStu === '') {
            continue;
        }

        $newStuSystemName = trim(getRowValue($row, $newStuIndex));
        $rows[] = [
            'stu_system_name' => trim($stuSystemName),
            'new_stu_system_name' => $newStuSystemName,
        ];

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

    return [
        'lookup' => $lookup,
        'rows' => $rows,
    ];
}

function processNamesetFile(array $fedRows, string $namesetPath, string $outputDir, string $timestamp): array
{
    $handle = openCsvForRead($namesetPath);
    $header = readCsvHeader($handle, $namesetPath);
    $headerMap = buildHeaderMap($header);

    $portNameIndex = getRequiredHeaderIndex($headerMap, 'Port Name', $namesetPath);
    $suffixColumnIndexes = buildNamesetSuffixColumnIndexes($headerMap);
    $carryForwardColumnIndexes = buildNamesetCarryForwardColumnIndexes($headerMap);
    $namesetRowLookup = [];
    $duplicateNamesetCount = 0;

    while (($row = readCsvRow($handle)) !== false) {
        $normalizedPortName = normalizeValue(getRowValue($row, $portNameIndex));
        if ($normalizedPortName === '') {
            continue;
        }

        if (!isset($namesetRowLookup[$normalizedPortName])) {
            $namesetRowLookup[$normalizedPortName] = $row;
            continue;
        }

        $duplicateNamesetCount++;
    }

    fclose($handle);

    if ($duplicateNamesetCount > 0) {
        fwrite(
            STDERR,
            "Warning: encountered {$duplicateNamesetCount} duplicate Port Name value(s) in the nameset file; using the first occurrence for each match." . PHP_EOL
        );
    }

    $outputPath = buildOutputPath($outputDir, 'nameset', $timestamp);
    $outputHandle = openCsvForWrite($outputPath);
    writeCsvRow($outputHandle, $header);

    $matchCount = 0;
    $rowCount = 0;

    foreach ($fedRows as $fedRow) {
        $normalizedStuSystemName = normalizeValue($fedRow['stu_system_name']);
        if ($normalizedStuSystemName === '' || !isset($namesetRowLookup[$normalizedStuSystemName])) {
            continue;
        }

        $matchedSourceRow = $namesetRowLookup[$normalizedStuSystemName];
        $matchedRow = buildAdjustedNamesetRow(
            $matchedSourceRow,
            $portNameIndex,
            $suffixColumnIndexes
        );
        writeCsvRow($outputHandle, $matchedRow);
        $matchCount++;
        $rowCount++;

        $normalizedNewStuSystemName = normalizeValue($fedRow['new_stu_system_name']);
        if ($normalizedNewStuSystemName === '' || !isset($namesetRowLookup[$normalizedNewStuSystemName])) {
            continue;
        }

        $mergedRow = buildMergedNamesetRow(
            $namesetRowLookup[$normalizedNewStuSystemName],
            $matchedSourceRow,
            $carryForwardColumnIndexes,
            count($header)
        );
        writeCsvRow($outputHandle, $mergedRow);
        $rowCount++;
    }

    fclose($outputHandle);

    return [
        'match_count' => $matchCount,
        'row_count' => $rowCount,
        'path' => $outputPath,
    ];
}

function processTagFile(array $fedRows, string $tagPath, string $outputDir, string $timestamp): array
{
    $handle = openCsvForRead($tagPath);
    $header = readCsvHeader($handle, $tagPath);
    $headerMap = buildHeaderMap($header);

    $nameSystemIndex = getRequiredHeaderIndex($headerMap, 'NAME (System)', $tagPath);
    $tagRowLookup = [];
    $duplicateTagCount = 0;

    while (($row = readCsvRow($handle)) !== false) {
        $normalizedNameSystem = normalizeValue(getRowValue($row, $nameSystemIndex));
        if ($normalizedNameSystem === '') {
            continue;
        }

        if (!isset($tagRowLookup[$normalizedNameSystem])) {
            $tagRowLookup[$normalizedNameSystem] = $row;
            continue;
        }

        $duplicateTagCount++;
    }

    fclose($handle);

    if ($duplicateTagCount > 0) {
        fwrite(
            STDERR,
            "Warning: encountered {$duplicateTagCount} duplicate NAME (System) value(s) in the tag file; using the first occurrence for each match." . PHP_EOL
        );
    }

    $outputPath = buildOutputPath($outputDir, 'tag', $timestamp);
    $outputHandle = openCsvForWrite($outputPath);
    writeCsvRow($outputHandle, $header);

    $matchCount = 0;
    $rowCount = 0;
    $columnCount = count($header);

    foreach ($fedRows as $fedRow) {
        $normalizedStuSystemName = normalizeValue($fedRow['stu_system_name']);
        if ($normalizedStuSystemName === '' || !isset($tagRowLookup[$normalizedStuSystemName])) {
            continue;
        }

        $matchedRow = $tagRowLookup[$normalizedStuSystemName];
        $firstTwoRow = buildStrippedTagRow($matchedRow, $columnCount);
        writeCsvRow($outputHandle, $firstTwoRow);
        $matchCount++;
        $rowCount++;

        $normalizedNewStuSystemName = normalizeValue($fedRow['new_stu_system_name']);
        if ($normalizedNewStuSystemName === '' || !isset($tagRowLookup[$normalizedNewStuSystemName])) {
            continue;
        }

        $newMatchedRow = $tagRowLookup[$normalizedNewStuSystemName];
        $mergedRow = buildMergedTagRow($newMatchedRow, $matchedRow, $columnCount);
        writeCsvRow($outputHandle, $mergedRow);
        $rowCount++;
    }

    fclose($outputHandle);

    return [
        'match_count' => $matchCount,
        'row_count' => $rowCount,
        'path' => $outputPath,
    ];
}

function processInterfaceFile(array $fedLookup, string $interfacePath, string $outputDir, string $timestamp): array
{
    $handle = openCsvForRead($interfacePath);
    $header = readCsvHeader($handle, $interfacePath);
    $headerMap = buildHeaderMap($header);

    $interfaceNameIndex = getRequiredHeaderIndex($headerMap, 'Interface Name', $interfacePath);
    $portSystemNameIndex = getRequiredHeaderIndex($headerMap, 'Port System Name', $interfacePath);

    $interfaceGroups = [];

    while (($row = readCsvRow($handle)) !== false) {
        $interfaceName = trim(getRowValue($row, $interfaceNameIndex));
        $interfaceKey = normalizeValue($interfaceName);
        $displayName = $interfaceName !== '' ? $interfaceName : 'Unnamed Interface';

        if (!isset($interfaceGroups[$interfaceKey])) {
            $interfaceGroups[$interfaceKey] = [
                'interface_name' => $displayName,
                'rows' => [],
            ];
        }

        $interfaceGroups[$interfaceKey]['rows'][] = $row;
    }

    fclose($handle);

    $results = [];
    foreach ($interfaceGroups as $interfaceKey => $group) {
        $path = buildOutputPath(
            $outputDir,
            'interface_' . sanitizeFilenameComponent($group['interface_name']),
            $timestamp
        );
        $writerHandle = openCsvForWrite($path);
        writeCsvRow($writerHandle, $header);

        $lastMatchIndexByPortSystemName = [];
        foreach ($group['rows'] as $rowIndex => $row) {
            $normalizedPortSystemName = normalizeValue(getRowValue($row, $portSystemNameIndex));
            if ($normalizedPortSystemName === '' || !isset($fedLookup[$normalizedPortSystemName])) {
                continue;
            }

            $lastMatchIndexByPortSystemName[$normalizedPortSystemName] = $rowIndex;
        }

        $matchCount = 0;
        foreach ($group['rows'] as $rowIndex => $row) {
            $normalizedPortSystemName = normalizeValue(getRowValue($row, $portSystemNameIndex));
            if ($normalizedPortSystemName !== '' && isset($fedLookup[$normalizedPortSystemName])) {
                if (
                    isset($lastMatchIndexByPortSystemName[$normalizedPortSystemName])
                    && $lastMatchIndexByPortSystemName[$normalizedPortSystemName] !== $rowIndex
                ) {
                    continue;
                }

                $row[$portSystemNameIndex] = $fedLookup[$normalizedPortSystemName]['new_stu_system_name'];
                $matchCount++;
            }

            writeCsvRow($writerHandle, $row);
        }

        fclose($writerHandle);
        $results[] = [
            'path' => $path,
            'interface_name' => $group['interface_name'],
            'match_count' => $matchCount,
        ];
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

function readCsvRow($handle): array|false
{
    return fgetcsv($handle, 0, CSV_SEPARATOR, CSV_ENCLOSURE, CSV_ESCAPE);
}

function writeCsvRow($handle, array $row): void
{
    if (fputcsv($handle, $row, CSV_SEPARATOR, CSV_ENCLOSURE, CSV_ESCAPE) === false) {
        fwrite(STDERR, "Unable to write CSV row." . PHP_EOL);
        exit(1);
    }
}

function readCsvHeader($handle, string $path): array
{
    $header = readCsvRow($handle);
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

function buildNamesetSuffixColumnIndexes(array $headerMap): array
{
    $suffixColumnNames = [
        'Global',
        'Generic',
        'Remote',
        'Local',
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

    return $suffixColumnIndexes;
}

function buildNamesetCarryForwardColumnIndexes(array $headerMap): array
{
    $carryForwardColumnNames = [
        'Global',
        'Generic',
        'Remote',
        'Local',
        'Remote Local',
        'ALIAS-DNF',
        'Hardware Loc',
        'HP',
    ];

    $carryForwardColumnIndexes = [];
    foreach ($carryForwardColumnNames as $columnName) {
        $normalized = normalizeHeaderName($columnName);
        if (isset($headerMap[$normalized])) {
            $carryForwardColumnIndexes[] = $headerMap[$normalized];
        }
    }

    return $carryForwardColumnIndexes;
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

function buildAdjustedNamesetRow(array $row, int $portNameIndex, array $suffixColumnIndexes): array
{
    if (shouldAppendFedSuffix($row, $suffixColumnIndexes)) {
        $row[$portNameIndex] = appendFedSuffix(getRowValue($row, $portNameIndex));
    }

    return appendFedSuffixToColumns($row, $suffixColumnIndexes);
}

function appendFedSuffixToColumns(array $row, array $columnIndexes): array
{
    foreach ($columnIndexes as $columnIndex) {
        if (trim(getRowValue($row, $columnIndex)) === '') {
            continue;
        }

        $row[$columnIndex] = appendFedSuffix(getRowValue($row, $columnIndex));
    }

    return $row;
}

function appendFedSuffix(string $value): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return FED_SUFFIX;
    }

    if (preg_match('/\s+fed$/i', $trimmed) === 1) {
        return $trimmed;
    }

    return $trimmed . FED_SUFFIX;
}

function buildMergedNamesetRow(array $newMatchedRow, array $previousMatchedRow, array $columnIndexes, int $columnCount): array
{
    $mergedRow = array_fill(0, $columnCount, '');

    for ($index = 0; $index < $columnCount; $index++) {
        $mergedRow[$index] = getRowValue($newMatchedRow, $index);
    }

    foreach ($columnIndexes as $columnIndex) {
        if (trim(getRowValue($mergedRow, $columnIndex)) !== '') {
            continue;
        }

        $mergedRow[$columnIndex] = getRowValue($previousMatchedRow, $columnIndex);
    }

    return $mergedRow;
}

function buildStrippedTagRow(array $row, int $columnCount): array
{
    $strippedRow = array_fill(0, $columnCount, '');
    if ($columnCount > 0) {
        $strippedRow[0] = getRowValue($row, 0);
    }

    if ($columnCount > 1) {
        $strippedRow[1] = getRowValue($row, 1);
    }

    return $strippedRow;
}

function buildMergedTagRow(array $newMatchedRow, array $previousMatchedRow, int $columnCount): array
{
    $mergedRow = array_fill(0, $columnCount, '');

    if ($columnCount > 0) {
        $mergedRow[0] = getRowValue($newMatchedRow, 0);
    }

    if ($columnCount > 1) {
        $mergedRow[1] = getRowValue($newMatchedRow, 1);
    }

    for ($index = 2; $index < $columnCount; $index++) {
        $mergedRow[$index] = getRowValue($previousMatchedRow, $index);
    }

    return $mergedRow;
}

function buildOutputPath(string $outputDir, string $baseName, string $timestamp): string
{
    return $outputDir . DIRECTORY_SEPARATOR . $timestamp . '_' . $baseName . '.csv';
}

function sanitizeFilenameComponent(string $value): string
{
    $sanitized = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($value)) ?? 'unnamed-interface';
    $sanitized = trim($sanitized, '._-');

    return $sanitized !== '' ? $sanitized : 'unnamed-interface';
}
