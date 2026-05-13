<?php
/**
 * HRECOS Water Data Backfill Script
 *
 * Fetches historical water-quality data from the NYSM/HRECOS API one day at a
 * time, from a given start date through today (inclusive), and inserts it into
 * the per-parameter usgs_water_data_* tables.
 *
 * Usage:
 *   php backfill.php YYYY-MM-DD
 *
 * Example:
 *   php backfill.php 2024-06-15
 */

// -----------------------------------------------------------------------------
// CLI guard
// -----------------------------------------------------------------------------
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

if ($argc < 2) {
    fwrite(STDERR, "Usage: php backfill.php YYYY-MM-DD\n");
    exit(1);
}

// -----------------------------------------------------------------------------
// Argument validation
// -----------------------------------------------------------------------------
$startDateStr = $argv[1];
$startDate = DateTime::createFromFormat('Y-m-d', $startDateStr);
if (!$startDate || $startDate->format('Y-m-d') !== $startDateStr) {
    fwrite(STDERR, "Invalid date format. Expected YYYY-MM-DD, got: $startDateStr\n");
    exit(1);
}
$startDate->setTime(0, 0, 0);

// The HRECOS API uses EST year-round. Use NY local time for "today" so the
// loop boundary lines up with the API's notion of a day.
date_default_timezone_set('America/New_York');
$today = new DateTime('today');
$today->setTime(0, 0, 0);

if ($startDate > $today) {
    fwrite(STDERR, "Start date is in the future: $startDateStr\n");
    exit(1);
}

// -----------------------------------------------------------------------------
// Configuration
// -----------------------------------------------------------------------------

// All HPIER data is stored under the legacy Pier 26 site code (integer).
const SITE_CODE = 1376520;

const HRECOS_URL_BASE = 'https://nysm.hrecos.org/api/data/dynserv/hrecos/exportsite/csv/HPIER';

// Map: CSV column key => [destination table, multiplier for unit conversion].
// Multiplier is applied to the API value before storing.
$tables = [
    'wtmp'   => ['table' => 'usgs_water_data_water_temp',        'convert' => 1.0],
    'tair'   => ['table' => 'usgs_water_data_air_temp',          'convert' => 1.0],
    'wdir'   => ['table' => 'usgs_water_data_wind_direction',    'convert' => 1.0],
    'sdwdir' => ['table' => 'usgs_water_data_sdwdir',            'convert' => 1.0],
    'precip' => ['table' => 'usgs_water_data_precipitation',     'convert' => 0.0393701], // mm -> in
    'relh'   => ['table' => 'usgs_water_data_humidity',          'convert' => 1.0],
    'spco'   => ['table' => 'usgs_water_data_water_conductance', 'convert' => 1.0],
    'doconc' => ['table' => 'usgs_water_data_do_mpl',            'convert' => 1.0],
    'dopc'   => ['table' => 'usgs_water_data_do_percent',        'convert' => 1.0],
    'ph'     => ['table' => 'usgs_water_data_ph',                'convert' => 1.0],
    'depth'  => ['table' => 'usgs_water_data_elevation',         'convert' => 3.28084],   // m -> ft
    'turb'   => ['table' => 'usgs_water_data_turbidity',         'convert' => 1.0],
    'pres'   => ['table' => 'usgs_water_data_baro_pressure',     'convert' => 0.750062],  // mbar -> mmHg
    'wspd'   => ['table' => 'usgs_water_data_wind_speed',        'convert' => 1.0],
    'salt'   => ['table' => 'usgs_water_data_salinity',          'convert' => 1.0],
    'chla'   => ['table' => 'usgs_water_data_chla',              'convert' => 1.0],
    'phyco'  => ['table' => 'usgs_water_data_phyco',             'convert' => 1.0],
    'par'    => ['table' => 'usgs_water_data_par',               'convert' => 1.0],
];

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

/**
 * Validate a value that should be numeric. Returns the float, or null if the
 * value is missing, empty, or not numeric. NaN values are also rejected.
 */
function validateNumber($s) {
    if (!isset($s) || is_null($s)) return null;
    if (!is_string($s)) {
        if (is_numeric($s)) {
            $v = (float)$s;
            return is_nan($v) ? null : $v;
        }
        return null;
    }
    $s = trim($s);
    if ($s === "") return null;
    if (!is_numeric($s)) return null;
    $v = (float)$s;
    if (is_nan($v)) return null;
    return $v;
}

/**
 * Parse an HRECOS timestamp like "20260513T000000-0500" and return a UTC
 * datetime string formatted "Y-m-d H:i:s" suitable for MySQL DATETIME.
 * Returns null on parse failure.
 */
function hrecosTimestampToUTC($datestring) {
    $dateTime = DateTime::createFromFormat('Ymd\THisO', trim($datestring));
    if ($dateTime === false) return null;
    $dateTime->setTimezone(new DateTimeZone('UTC'));
    return $dateTime->format('Y-m-d H:i:s');
}

/**
 * From a CSV header like "wtmp [degC]" return just "wtmp".
 */
function extractColumnKey($header) {
    $header = trim($header);
    $bracket = strpos($header, '[');
    if ($bracket !== false) {
        return trim(substr($header, 0, $bracket));
    }
    return $header;
}

// -----------------------------------------------------------------------------
// DB connection and prepared statements
// -----------------------------------------------------------------------------
require "db.inc";

$mysqli = new mysqli($hostname, $db_username, $db_password, $db_name);
if ($mysqli->connect_errno) {
    fwrite(STDERR, "DB connection failed: " . $mysqli->connect_error . "\n");
    exit(1);
}

$stmts = [];
foreach ($tables as $col => $info) {
    $sql = "INSERT INTO " . $info['table']
         . " (recorded_date, site, value) VALUES (?, ?, ?)"
         . " ON DUPLICATE KEY UPDATE value = ?";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        fwrite(STDERR, "Prepare failed for " . $info['table'] . ": " . $mysqli->error . "\n");
        exit(1);
    }
    $stmts[$col] = $stmt;
}

// -----------------------------------------------------------------------------
// cURL handle (reused across all daily requests)
// -----------------------------------------------------------------------------
$handle = curl_init();
curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
curl_setopt($handle, CURLOPT_TIMEOUT, 60);
curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 15);
curl_setopt($handle, CURLOPT_FOLLOWLOCATION, true);

// -----------------------------------------------------------------------------
// Process a single day. Throws Exception on failure.
// Returns array: ['rows_received' => N, <col> => count, ...]
// -----------------------------------------------------------------------------
function processDay(DateTime $day, $handle, array $tables, array $stmts) {
    $apiFrom = $day->format('Ymd') . 'T0000';
    $apiTo   = $day->format('Ymd') . 'T2359';
    $url     = HRECOS_URL_BASE . "/$apiFrom/$apiTo";

    curl_setopt($handle, CURLOPT_URL, $url);
    $response = curl_exec($handle);

    if ($response === false) {
        throw new Exception("cURL error: " . curl_error($handle));
    }
    $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
    if ($httpCode !== 200) {
        throw new Exception("HTTP $httpCode");
    }
    $response = trim($response);
    if ($response === "") {
        throw new Exception("Empty response body");
    }

    $lines = preg_split('/\r\n|\r|\n/', $response);
    if (count($lines) < 1) {
        throw new Exception("No lines in response");
    }

    // Parse header row
    $headerCells = str_getcsv($lines[0]);
    $columnIndex = [];
    foreach ($headerCells as $i => $cell) {
        $key = extractColumnKey($cell);
        $columnIndex[$key] = $i;
    }
    if (!isset($columnIndex['datetime'])) {
        throw new Exception("No 'datetime' column in response header");
    }
    $dtIdx = $columnIndex['datetime'];

    // Initialize counts
    $counts = ['rows_received' => 0];
    foreach ($tables as $col => $info) {
        $counts[$col] = 0;
    }

    // Iterate data rows
    $lineCount = count($lines);
    for ($i = 1; $i < $lineCount; $i++) {
        $row = str_getcsv($lines[$i]);
        // Need at least enough columns to reach the datetime field
        if (count($row) <= $dtIdx) continue;

        $recordedDate = hrecosTimestampToUTC($row[$dtIdx]);
        if (is_null($recordedDate)) continue;

        $counts['rows_received']++;

        // For each mapped measurement column, insert if value is valid
        foreach ($tables as $col => $info) {
            if (!isset($columnIndex[$col])) continue;
            $idx = $columnIndex[$col];
            if (!isset($row[$idx])) continue;

            $rawValue = $row[$idx];
            $value = validateNumber($rawValue);
            if (is_null($value)) continue;

            // Apply unit conversion
            $value = $value * $info['convert'];

            $siteCode = SITE_CODE;
            $stmt = $stmts[$col];
            $stmt->bind_param("sidd", $recordedDate, $siteCode, $value, $value);
            if (!$stmt->execute()) {
                // Log to stderr and continue with next value rather than
                // failing the whole day on a single bad row.
                fwrite(STDERR, "  execute failed for $col at $recordedDate: " . $stmt->error . "\n");
                continue;
            }
            $counts[$col]++;
        }
    }

    return $counts;
}

// -----------------------------------------------------------------------------
// Main loop
// -----------------------------------------------------------------------------
$totals = ['rows_received' => 0];
foreach ($tables as $col => $info) {
    $totals[$col] = 0;
}
$failedDays = [];
$daysProcessed = 0;
$daysSucceeded = 0;

$current = clone $startDate;

echo "Backfill from " . $startDate->format('Y-m-d')
   . " through " . $today->format('Y-m-d')
   . " (site=" . SITE_CODE . ")\n";
echo str_repeat('-', 72) . "\n";

while ($current <= $today) {
    $dateStr = $current->format('Y-m-d');
    $daysProcessed++;

    try {
        $counts = processDay($current, $handle, $tables, $stmts);

        $rowsReceived = $counts['rows_received'];
        $totals['rows_received'] += $rowsReceived;

        $parts = [];
        foreach ($tables as $col => $info) {
            $totals[$col] += $counts[$col];
            if ($counts[$col] > 0) {
                $parts[] = "$col={$counts[$col]}";
            }
        }
        $summary = empty($parts) ? "(no values inserted)" : implode(', ', $parts);
        echo "[$dateStr] $rowsReceived rows received -- $summary\n";
        $daysSucceeded++;

    } catch (Exception $e) {
        echo "[$dateStr] FAILED: " . $e->getMessage() . "\n";
        $failedDays[] = $dateStr;
    }

    $current->modify('+1 day');

    // Be polite between requests
    if ($current <= $today) {
        sleep(1);
    }
}

// -----------------------------------------------------------------------------
// Summary
// -----------------------------------------------------------------------------
echo str_repeat('-', 72) . "\n";
echo "Done. Days processed: $daysProcessed (succeeded: $daysSucceeded, failed: "
   . count($failedDays) . ")\n";
echo "Total rows received: " . $totals['rows_received'] . "\n";
echo "Inserts/updates per parameter:\n";
foreach ($tables as $col => $info) {
    printf("  %-7s -> %-40s %d\n", $col, $info['table'], $totals[$col]);
}
if (!empty($failedDays)) {
    echo "Failed days (" . count($failedDays) . "): " . implode(', ', $failedDays) . "\n";
}

// -----------------------------------------------------------------------------
// Cleanup
// -----------------------------------------------------------------------------
foreach ($stmts as $stmt) {
    $stmt->close();
}
curl_close($handle);
$mysqli->close();

exit(0);