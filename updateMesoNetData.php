<?php
/**
 * HRECOS Water Data Cron Fetcher
 *
 * Fetches the last 24 hours of water-quality data from the NYSM/HRECOS API
 * and inserts it into the per-parameter usgs_water_data_* tables.
 *
 * Intended to run every 15 minutes via cron. The 24-hour lookback is much
 * longer than necessary, but provides self-healing if the cron skips a few
 * runs -- ON DUPLICATE KEY UPDATE makes overlapping fetches harmless.
 *
 */

// -----------------------------------------------------------------------------
// Configuration
// -----------------------------------------------------------------------------

// All HPIER data is stored under the legacy Pier 26 site code (integer).
const SITE_CODE = 1376520;

const HRECOS_URL_BASE = 'https://nysm.hrecos.org/api/data/dynserv/hrecos/exportsite/csv/HPIER';

// HRECOS uses EST year-round (no DST). Compute "now" and "24 hours ago" in
// EST by working in UTC and applying a fixed -5 hour offset for formatting.
// This is independent of the server's local timezone.
const EST_OFFSET_HOURS = -5;

// Map: CSV column key => [destination table, multiplier for unit conversion].
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
 * datetime string suitable for MySQL DATETIME. Returns null on parse failure.
 */
function hrecosTimestampToUTC($datestring) {
    $dt = DateTime::createFromFormat('Ymd\THisO', trim($datestring));
    if ($dt === false) return null;
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Y-m-d H:i:s');
}

function extractColumnKey($header) {
    $header = trim($header);
    $bracket = strpos($header, '[');
    if ($bracket !== false) {
        return trim(substr($header, 0, $bracket));
    }
    return $header;
}

function logLine($msg) {
    echo "[" . gmdate('Y-m-d H:i:s') . " UTC] " . $msg . "\n";
}

function logError($msg) {
    fwrite(STDERR, "[" . gmdate('Y-m-d H:i:s') . " UTC] ERROR: " . $msg . "\n");
}

// -----------------------------------------------------------------------------
// DB connection and prepared statements
// -----------------------------------------------------------------------------
require "db.inc";

$mysqli = new mysqli($hostname, $db_username, $db_password, $db_name);
if ($mysqli->connect_errno) {
    logError("DB connection failed: " . $mysqli->connect_error);
    exit(1);
}

$stmts = [];
foreach ($tables as $col => $info) {
    $sql = "INSERT INTO " . $info['table']
         . " (recorded_date, site, value) VALUES (?, ?, ?)"
         . " ON DUPLICATE KEY UPDATE value = ?";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        logError("Prepare failed for " . $info['table'] . ": " . $mysqli->error);
        exit(1);
    }
    $stmts[$col] = $stmt;
}

// -----------------------------------------------------------------------------
// Compute the 24-hour window in EST (UTC-5, no DST)
// -----------------------------------------------------------------------------
$nowUtc = new DateTime('now', new DateTimeZone('UTC'));
$nowEst = clone $nowUtc;
$nowEst->modify(EST_OFFSET_HOURS . ' hours');
$startEst = clone $nowEst;
$startEst->modify('-1 day');

$apiFrom = $startEst->format('Ymd\THi');
$apiTo   = $nowEst->format('Ymd\THi');

// -----------------------------------------------------------------------------
// Fetch HRECOS data
// -----------------------------------------------------------------------------
$handle = curl_init();
curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
curl_setopt($handle, CURLOPT_TIMEOUT, 60);
curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 15);
curl_setopt($handle, CURLOPT_FOLLOWLOCATION, true);

$url = HRECOS_URL_BASE . "/$apiFrom/$apiTo";
curl_setopt($handle, CURLOPT_URL, $url);

$rowsReceived = 0;
$insertedCounts = [];
foreach ($tables as $col => $info) {
    $insertedCounts[$col] = 0;
}

try {
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

    // Parse header
    $headerCells = str_getcsv($lines[0]);
    $columnIndex = [];
    foreach ($headerCells as $i => $cell) {
        $columnIndex[extractColumnKey($cell)] = $i;
    }
    if (!isset($columnIndex['datetime'])) {
        throw new Exception("No 'datetime' column in response header");
    }
    $dtIdx = $columnIndex['datetime'];

    $lineCount = count($lines);
    for ($i = 1; $i < $lineCount; $i++) {
        $row = str_getcsv($lines[$i]);
        if (count($row) <= $dtIdx) continue;

        $recordedDate = hrecosTimestampToUTC($row[$dtIdx]);
        if (is_null($recordedDate)) continue;

        $rowsReceived++;

        foreach ($tables as $col => $info) {
            if (!isset($columnIndex[$col])) continue;
            $idx = $columnIndex[$col];
            if (!isset($row[$idx])) continue;

            $value = validateNumber($row[$idx]);
            if (is_null($value)) continue;

            $value = $value * $info['convert'];
            $siteCode = SITE_CODE;

            $stmt = $stmts[$col];
            $stmt->bind_param("sidd", $recordedDate, $siteCode, $value, $value);
            if (!$stmt->execute()) {
                logError("execute failed for $col at $recordedDate: " . $stmt->error);
                continue;
            }
            $insertedCounts[$col]++;
        }
    }

    // Build one-line summary
    $parts = [];
    foreach ($tables as $col => $info) {
        if ($insertedCounts[$col] > 0) {
            $parts[] = "$col={$insertedCounts[$col]}";
        }
    }
    $summary = empty($parts) ? "(no values)" : implode(', ', $parts);
    logLine("HRECOS window $apiFrom -> $apiTo: $rowsReceived rows -- $summary");

} catch (Exception $e) {
    logError("HRECOS fetch failed: " . $e->getMessage());
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