# Magnum STU Federation Replacement Script

This repository contains a PHP command-line script that reads four CSV files and generates timestamped output CSV files based on matching rules between the fed, nameset, tag, and interface data.

## Script

`process_fed_csvs.php`

## Requirements

- PHP CLI
- Input files in CSV format
- The first row of each CSV must be the header row

## Usage

```bash
php process_fed_csvs.php \
  --fed=/path/to/fed.csv \
  --nameset=/path/to/nameset.csv \
  --tag=/path/to/tag.csv \
  --interface=/path/to/interface.csv \
  --output-dir=/path/to/output
```

Positional arguments are also supported:

```bash
php process_fed_csvs.php \
  /path/to/fed.csv \
  /path/to/nameset.csv \
  /path/to/tag.csv \
  /path/to/interface.csv \
  /path/to/output
```

If `--output-dir` is not provided, the script writes output files into an `output/` directory under the current working directory.

## Matching Rules

All matching is:

- case-insensitive
- trimmed for leading and trailing whitespace

The script is configured for large CSV files:

- PHP memory limit is set to unlimited
- execution time limit is disabled

## Required Input Columns

### Fed file

- `STU System Name`
- `New STU System Name`
- `STU TOPS Name`

### Nameset file

- `Port Name`
- optional nameset name columns used by the script:
  - `Global`
  - `Generic`
  - `Remote`
  - `Local`
  - `Remote Local`
  - `ALIAS-DNF`
  - `Hardware Loc`
  - `TOPS`
  - `HP`

### Tag file

- `NAME (System)`

### Interface file

- `Interface Name`
- `Port System Name`
- `SRC/DST`

## Output Files

All output files start with a timestamp in this format:

`YYYYMMDD_HHMM`

Examples:

- `20260803_1748_nameset.csv`
- `20260803_1748_nameset_original.csv`
- `20260803_1748_tag.csv`
- `20260803_1748_tag_original.csv`
- `20260803_1748_interface_IF-1.csv`
- `20260803_1748_interface_duplicates.csv`

## Nameset Output

The script writes one nameset output file:

- `{timestamp}_nameset.csv`

The first row of the output file is the nameset header row.

The script also writes an original nameset match file:

- `{timestamp}_nameset_original.csv`

This file uses the nameset header and contains the unmodified source row for every first nameset match between `fed.STU System Name` and `nameset.Port Name`. It does not include the ` FED` suffix or second-match changes.

### First nameset match

For each fed row:

1. Match `fed.STU System Name` to `nameset.Port Name`
2. If a match is found, write the nameset row

Before writing the first matched nameset row:

- if any supported nameset name columns are populated, append ` FED` to:
  - `Port Name`
  - `Global`
  - `Generic`
  - `Remote`
  - `Local`
  - `Remote Local`
  - `ALIAS-DNF`
  - `Hardware Loc`
  - `TOPS`
  - `HP`
- if a value already ends with ` FED`, the script does not append it again

### Second nameset match

After the first nameset match:

1. Look up `fed.New STU System Name`
2. Match that value to `nameset.Port Name`
3. If a second match is found, write the second nameset row to the same output file

When writing the second matched nameset row:

- the second row keeps its own `Port Name`
- the second row keeps its own `TOPS`
- for these columns, if the second matched row already has a non-empty value, that value is kept:
  - `Global`
  - `Generic`
  - `Remote`
  - `Local`
  - `Remote Local`
  - `ALIAS-DNF`
  - `Hardware Loc`
  - `HP`
- if one of those columns is empty in the second matched row, the value is copied from the first matched row
- copied values come from the original first matched nameset row, not the suffixed output row

### Nameset terminal output

The script echoes:

- match count
- total rows written

## Tag Output

The script writes one tag output file:

- `{timestamp}_tag.csv`

The first row of the output file is the tag header row.

The script also writes an original tag match file:

- `{timestamp}_tag_original.csv`

This file uses the tag header and contains the complete, unmodified source row for every first tag match between `fed.STU System Name` and `tag.NAME (System)`.

For each fed row:

### First tag match

1. Match `fed.STU System Name` to `tag.NAME (System)`
2. If found, write a row containing only the first two tag columns
3. Columns greater than 2 are blank in that written row

### Second tag match

1. Look up `fed.New STU System Name`
2. Match that value to `tag.NAME (System)`
3. If found, write a second tag row where:
   - columns 1 and 2 come from the second matched tag row
   - columns 3 and above come from the first matched tag row

### Tag terminal output

The script echoes:

- first-match count
- total rows written

## Interface Output

The script writes one interface output file per unique `Interface Name`:

- `{timestamp}_interface_<interface-name>.csv`

The first row of each output file is the interface header row.

For each interface row:

1. Only rows whose `SRC/DST` value is `SRC` participate in fed matching and duplicate detection
2. Match each source `interface.Port System Name` to `fed.STU System Name`
3. If there is a fed match, replace `Port System Name` with `fed.New STU System Name`
4. Normalize the final mapped `Port System Name` using case-insensitive comparison and trimmed whitespace
5. Group source rows by that normalized final mapped name within each `Interface Name`
6. If multiple source rows produce the same final mapped name, only the last occurrence is written
7. Non-`SRC` rows are written unchanged and are not counted as matches or duplicates

### Interface duplicate output

The script writes one combined duplicate-report file:

- `{timestamp}_interface_duplicates.csv`

The file contains one row for every interface row omitted as a duplicate, with these columns:

- `Interface Name`
- `Port System Name`
- `New STU System Name`
- `STU TOPS Name`

`Port System Name` is the original value from the omitted interface row. The new STU and TOPS names are resolved from the fed file. If the omitted port name has no fed mapping, those two fields are blank.

### Interface terminal output

The script echoes one line per interface output file showing:

- interface name
- number of matched/replaced rows
- number of duplicate rows removed

## Duplicate Match Behavior

When duplicate keys are found, the script keeps the first matching row and writes a warning to stderr:

- duplicate `STU System Name` values in the fed file
- duplicate `Port Name` values in the nameset file
- duplicate `NAME (System)` values in the tag file

## Notes

- Header matching is tolerant of extra internal whitespace such as `Remote Local`
- The script removes a UTF-8 BOM from the first header cell when present
