<?php
/**
 * NYS Mesonet Precipitation Cron Fetcher
 *
 * Fetches the rolling 24 hours of 5-minute precipitation from the NYS Mesonet
 * MANH station (Hudson River Park feed), sums it into 15-minute totals, and
 * upserts them into usgs_water_data_precipitation.
 *
 * Intended to run every 15 minutes via cron. Every run re-writes every complete
 * 15-minute bucket in the 24-hour window, so missed runs heal themselves and
 * ON DUPLICATE KEY UPDATE makes the overlap harmless.
 *
 * Source data
 *   CSV:  station,datetime,precip_5min [in]
 *         MANH,2026-10-30 12:35 PM EDT,0
 *   - Value is inches accumulated in the 5 minutes ENDING at the timestamp
 *     (10:00 covers 9:55:01-10:00:00).
 *   - The newest reading is a real-time estimate, replaced by its permanent
 *     value 5 minutes later. It is always dropped, so only final values are stored.
 *   - A missing reading is a blank field; its bucket is then incomplete and skipped.
 *   - Timestamps carry an EDT/EST label per row. That label is what makes the
 *     repeated 1:00-1:59 AM hour on the night DST ends unambiguous, so it is
 *     always parsed -- never assume a zone.
 *
 * Destination
 *   - Inches (no conversion; the API already reports inches).
 *   - recorded_date in UTC, marking the END of the 15-minute interval:
 *     the 10:15 row is the sum of the 10:05, 10:10 and 10:15 readings.
 *   - A bucket is written only when all three of its readings are present.
 *     This is required, not just tidy: the oldest bucket in the rolling window
 *     is usually cut off, and writing its partial sum would overwrite the
 *     complete value stored by an earlier run.
 *
 * Usage
 *   php updateMesoNetPrecip.php                 # fetch and write (cron)
 *   php updateMesoNetPrecip.php --dry-run       # fetch and print, no DB
 *   php updateMesoNetPrecip.php --dry-run --file=sample.csv   # parse a saved CSV
 */

// -----------------------------------------------------------------------------
// Configuration
// -----------------------------------------------------------------------------

// Stored under the same legacy site code as all HPIER data. The dashboard sums
// precipitation across sites 1376515 and 1376520, so precip must be written
// under exactly one of them.
const SITE_CODE = 1376520;

const MESONET_URL = 'https://api.nysmesonet.org/data/dynserv/hudson-river-park-hrecos/nysm/latest';

const VALUE_COLUMN     = 'precip_5min';
const READING_SECONDS  = 300;   // 5-minute source readings
const BUCKET_SECONDS   = 900;   // 15-minute destination rows
const READINGS_PER_BUCKET = BUCKET_SECONDS / READING_SECONDS;

// Plausibility range for a single 5-minute reading, in inches. Anything outside
// is treated as missing (which leaves its bucket incomplete, so it isn't written).
// 1.0 in / 5 min is far beyond any recorded NYC rainfall rate.
const PRECIP_5MIN_MIN = 0.0;
const PRECIP_5MIN_MAX = 1.0;

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

function validateNumber($s) {
    if (!is_string($s)) return null;
    $s = trim($s);
    if ($s === '' || !is_numeric($s)) return null;
    $v = (float)$s;
    return is_finite($v) ? $v : null;
}

function extractColumnKey($header) {
    $header = trim($header);
    $bracket = strpos($header, '[');
    return $bracket === false ? $header : trim(substr($header, 0, $bracket));
}

/**
 * "2026-10-30 12:35 PM EDT" -> Unix timestamp, or null if it doesn't parse or
 * isn't on a 5-minute boundary.
 */
function parseMesonetTimestamp($s) {
    $dt = DateTime::createFromFormat('!Y-m-d g:i A T', trim($s));
    if ($dt === false) return null;
    $errors = DateTime::getLastErrors();
    if ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) return null;
    $ts = $dt->getTimestamp();
    return ($ts % READING_SECONDS === 0) ? $ts : null;
}

/** End of the 15-minute bucket a reading belongs to: round UP to the quarter hour. */
function bucketEnd($ts) {
    return (int)(ceil($ts / BUCKET_SECONDS) * BUCKET_SECONDS);
}

function utcString($ts) {
    return gmdate('Y-m-d H:i:s', $ts);
}

function logLine($msg) {
    echo "[" . gmdate('Y-m-d H:i:s') . " UTC] " . $msg . "\n";
}

function logError($msg) {
    fwrite(STDERR, "[" . gmdate('Y-m-d H:i:s') . " UTC] ERROR: " . $msg . "\n");
}

/**
 * Parse the CSV and sum readings into 15-minute buckets.
 *
 * Returns [
 *   'readings' => number of valid readings,
 *   'rejected' => number of rows with an unparseable timestamp or implausible value,
 *   'buckets'  => [bucketEndTs => ['sum' => float, 'count' => int]], sorted ascending
 * ]
 */
function aggregateCsv($csv) {
    $lines = preg_split('/\r\n|\r|\n/', trim($csv));
    if (count($lines) < 2) throw new Exception("No data rows in response");

    $columnIndex = [];
    foreach (str_getcsv($lines[0]) as $i => $cell) {
        $columnIndex[extractColumnKey($cell)] = $i;
    }
    foreach (['datetime', VALUE_COLUMN] as $required) {
        if (!isset($columnIndex[$required])) {
            throw new Exception("No '$required' column in header: " . $lines[0]);
        }
    }
    $dtIdx  = $columnIndex['datetime'];
    $valIdx = $columnIndex[VALUE_COLUMN];

    $readings = [];   // readingTs => value (keyed, so a duplicated row counts once)
    $rejected = 0;
    for ($i = 1, $n = count($lines); $i < $n; $i++) {
        if (trim($lines[$i]) === '') continue;
        $row = str_getcsv($lines[$i]);

        $ts    = isset($row[$dtIdx])  ? parseMesonetTimestamp($row[$dtIdx]) : null;
        $value = isset($row[$valIdx]) ? validateNumber($row[$valIdx])       : null;

        if ($ts === null) { $rejected++; continue; }
        if ($value === null) continue;                       // blank = missing, not an error
        if ($value < PRECIP_5MIN_MIN || $value > PRECIP_5MIN_MAX) { $rejected++; continue; }

        $readings[$ts] = $value;
    }

    // The newest reading is the gauge's real-time estimate; Mesonet replaces it
    // with the permanent value 5 minutes later (it changes only once). Never use
    // it -- the bucket it belongs to is written on the next run, once final.
    if (!empty($readings)) unset($readings[max(array_keys($readings))]);

    $buckets = [];
    foreach ($readings as $ts => $value) {
        $b = bucketEnd($ts);
        if (!isset($buckets[$b])) $buckets[$b] = ['sum' => 0.0, 'count' => 0];
        $buckets[$b]['sum']   += $value;
        $buckets[$b]['count'] += 1;
    }
    ksort($buckets);

    return ['readings' => count($readings), 'rejected' => $rejected, 'buckets' => $buckets];
}

function fetchCsv($url) {
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $response = curl_exec($handle);
    $error    = curl_error($handle);
    $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    if ($response === false) throw new Exception("cURL error: $error");
    if ($httpCode !== 200)   throw new Exception("HTTP $httpCode");
    if (trim($response) === '') throw new Exception("Empty response body");
    return $response;
}

// -----------------------------------------------------------------------------
// Main
// -----------------------------------------------------------------------------

$opts   = getopt('', ['dry-run', 'file:']);
$dryRun = isset($opts['dry-run']);
$file   = $opts['file'] ?? null;

// Fetch and aggregate
try {
    $csv = $file !== null ? file_get_contents($file) : fetchCsv(MESONET_URL);
    if ($csv === false) throw new Exception("Could not read $file");
    $result = aggregateCsv($csv);
} catch (Exception $e) {
    logError("Mesonet precip fetch failed: " . $e->getMessage());
    exit(1);
}

$complete   = array_filter($result['buckets'], fn($b) => $b['count'] === READINGS_PER_BUCKET);
$incomplete = count($result['buckets']) - count($complete);

if ($dryRun) {
    foreach ($result['buckets'] as $ts => $b) {
        $status = $b['count'] === READINGS_PER_BUCKET ? '' : "  (incomplete: {$b['count']}/" . READINGS_PER_BUCKET . ", skipped)";
        printf("%s UTC  %.3f in%s\n", utcString($ts), $b['sum'], $status);
    }
    printf("%d readings, %d rejected, %d buckets to write, %d incomplete\n",
        $result['readings'], $result['rejected'], count($complete), $incomplete);
    exit(0);
}

// Write
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);   // errors throw (PHP 8.1+ default; explicit here)

try {
    require __DIR__ . '/db.inc';
    $mysqli = new mysqli($hostname, $db_username, $db_password, $db_name);
    $stmt = $mysqli->prepare(
        "INSERT INTO usgs_water_data_precipitation (recorded_date, site, value) VALUES (?, ?, ?)"
      . " ON DUPLICATE KEY UPDATE value = ?"
    );
} catch (mysqli_sql_exception $e) {
    logError("DB setup failed: " . $e->getMessage());
    exit(1);
}

$written = 0;
$failed  = 0;
$total   = 0.0;
$siteCode = SITE_CODE;
foreach ($complete as $ts => $b) {
    $recordedDate = utcString($ts);
    $value = round($b['sum'], 4);              // strip float noise from the sum
    try {
        $stmt->bind_param('sidd', $recordedDate, $siteCode, $value, $value);
        $stmt->execute();
        $written++;
        $total += $value;
    } catch (mysqli_sql_exception $e) {
        // One bad row must not abort the rest of the run.
        logError("insert failed at $recordedDate: " . $e->getMessage());
        $failed++;
    }
}

$stmt->close();
$mysqli->close();

$latest = empty($complete) ? 'none' : utcString(array_key_last($complete));
logLine(sprintf(
    "Mesonet MANH: %d readings -- %d buckets written, %d incomplete skipped, %d rejected, %d failed; latest %s UTC; 24h total %.3f in",
    $result['readings'], $written, $incomplete, $result['rejected'], $failed, $latest, $total
));

exit($failed > 0 ? 1 : 0);