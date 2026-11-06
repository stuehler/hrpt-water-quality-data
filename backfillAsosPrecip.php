<?php
/**
 * ASOS Central Park Precipitation Backfill (one-off CLI)
 *
 * Replaces usgs_water_data_precipitation for a window of time with hourly
 * precipitation from the NWS ASOS station in Central Park (KNYC), served by the
 * Iowa Environmental Mesonet (IEM).
 *
 * Why this source
 *   The USGS-era Pier 25 data is incomplete (no :15 rows, multi-week outages)
 *   and nothing was recorded from Apr 2026 until the Mesonet MANH feed started
 *   on 2026-10-30 17:00 UTC. Central Park's hourly reports are the official NWS
 *   record for NYC and are essentially complete. (Its 1-minute precip feed was
 *   tested and rejected: 10-35% of minutes missing, and Jan/Feb 2026 full of
 *   spurious values.)
 *
 * How hourly reports become 15-minute rows
 *   - The routine hourly report at HH:51 carries the precipitation since the
 *     previous report (~HH-1:52 to HH:51). It is assigned to the clock hour
 *     (HH:00, HH+1:00] -- rounded UP, the same rule updateMesoNetPrecip.php uses.
 *   - Each hourly total is split evenly across that hour's four 15-minute rows
 *     (stamped HH:15, HH:30, HH:45, HH+1:00, UTC), in thousandths of an inch.
 *     Any remainder goes to the earliest rows, so the four always sum exactly
 *     to the hourly value. Within-hour timing is not known and is shown evenly.
 *   - Trace ("T", served as 0.0001) is stored as 0.
 *   - A missing hourly report ("M") writes no rows for that hour -> a gap.
 *
 * Window
 *   Rows with START < recorded_date < END are deleted and replaced, for
 *   SITE_CODE only. END defaults to the first Mesonet MANH row, so the live
 *   feed's data is never touched. Everything happens in one transaction, and
 *   nothing touches the DB until every month has been fetched and parsed.
 *
 * Usage
 *   php backfillAsosPrecip.php --dry-run                  # fetch, print daily/monthly totals
 *   php backfillAsosPrecip.php                            # fetch, delete window, insert
 *   php backfillAsosPrecip.php --start=2025-09-01T00:00 --end=2026-10-30T17:00 [--dry-run]
 *   (all times UTC)
 *
 * Back up the table before running for real.
 */

// -----------------------------------------------------------------------------
// Configuration
// -----------------------------------------------------------------------------

const SITE_CODE     = 1376520;
const DEFAULT_START = '2025-09-01T00:00';   // UTC, exclusive
const DEFAULT_END   = '2026-10-30T17:00';   // UTC, exclusive -- first Mesonet MANH row

const IEM_URL = 'https://mesonet.agron.iastate.edu/cgi-bin/request/asos.py';
const STATION = 'NYC';

const SLOTS_PER_HOUR = 4;
const SLOT_SECONDS   = 900;
const SPLIT_SCALE    = 1000;   // split in thousandths of an inch (the table's precision)
const HOURLY_MAX_IN  = 5.0;    // plausibility ceiling for one hour

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

function logLine($msg)  { echo "[" . gmdate('Y-m-d H:i:s') . " UTC] " . $msg . "\n"; }
function logError($msg) { fwrite(STDERR, "[" . gmdate('Y-m-d H:i:s') . " UTC] ERROR: " . $msg . "\n"); }

function parseUtc($s) {
    $dt = DateTime::createFromFormat('!Y-m-d\TH:i', $s, new DateTimeZone('UTC'));
    if ($dt === false) throw new Exception("Bad time '$s' (expected YYYY-MM-DDTHH:MM, UTC)");
    return $dt->getTimestamp();
}

function fetchMonth($year, $month) {
    $next = gmmktime(0, 0, 0, $month + 1, 1, $year);
    $query = http_build_query([
        'station'     => STATION,
        'data'        => 'p01i',
        'year1'       => $year, 'month1' => $month, 'day1' => 1,
        'year2'       => (int)gmdate('Y', $next), 'month2' => (int)gmdate('n', $next), 'day2' => 1,
        'tz'          => 'Etc/UTC',
        'format'      => 'onlycomma',
        'latlon'      => 'no',
        'missing'     => 'M',
        'trace'       => '0.0001',
        'report_type' => 3,   // routine hourly reports only
    ]);

    $handle = curl_init(IEM_URL . '?' . $query);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $response = curl_exec($handle);
    $error    = curl_error($handle);
    $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    if ($response === false) throw new Exception("cURL error: $error");
    if ($httpCode !== 200)   throw new Exception("HTTP $httpCode: " . substr(trim($response), 0, 200));
    return $response;
}

/**
 * Parse one IEM CSV response into $hours: [hourEndTs => inches|null].
 * null means the report exists but precip is missing ("M").
 */
function parseHourly($csv, &$hours, &$stats) {
    $lines = preg_split('/\r\n|\r|\n/', trim($csv));
    if (trim($lines[0]) !== 'station,valid,p01i') {
        throw new Exception("Unexpected response header: " . substr($lines[0], 0, 200));
    }
    for ($i = 1, $n = count($lines); $i < $n; $i++) {
        $row = str_getcsv($lines[$i]);
        if (count($row) < 3) continue;

        $dt = DateTime::createFromFormat('!Y-m-d H:i', trim($row[1]), new DateTimeZone('UTC'));
        if ($dt === false) { $stats['bad_rows']++; continue; }
        $ts = $dt->getTimestamp();
        $hourEnd = (int)(ceil($ts / 3600) * 3600);   // HH:51 -> HH+1:00

        $raw = trim($row[2]);
        if ($raw === 'M' || $raw === '') {
            $value = null;
        } elseif (is_numeric($raw) && (float)$raw >= 0 && (float)$raw <= HOURLY_MAX_IN) {
            $value = ((float)$raw <= 0.0001) ? 0.0 : (float)$raw;   // trace -> 0
        } else {
            $stats['bad_rows']++;
            continue;
        }

        if (array_key_exists($hourEnd, $hours)) $stats['duplicates']++;
        // Keep a real value over a missing one; otherwise the later report wins.
        if ($value !== null || !array_key_exists($hourEnd, $hours) || $hours[$hourEnd] === null) {
            $hours[$hourEnd] = $value;
        }
    }
}

/** Split an hourly total into 4 slot values (thousandths) that sum exactly. */
function splitHour($inches) {
    $units = (int)round($inches * SPLIT_SCALE);
    $base  = intdiv($units, SLOTS_PER_HOUR);
    $rem   = $units % SLOTS_PER_HOUR;
    $out = [];
    for ($k = 0; $k < SLOTS_PER_HOUR; $k++) {
        $out[] = ($base + ($k < $rem ? 1 : 0)) / SPLIT_SCALE;
    }
    return $out;
}

// -----------------------------------------------------------------------------
// Main
// -----------------------------------------------------------------------------

$opts   = getopt('', ['dry-run', 'start:', 'end:', 'file:']);
$dryRun = isset($opts['dry-run']);

try {
    $start = parseUtc($opts['start'] ?? DEFAULT_START);
    $end   = parseUtc($opts['end']   ?? DEFAULT_END);
    if ($end <= $start) throw new Exception("--end must be after --start");
} catch (Exception $e) {
    logError($e->getMessage());
    exit(1);
}

// 1. Fetch and parse every month in the window. Nothing touches the DB until this succeeds.
$hours = [];
$stats = ['bad_rows' => 0, 'duplicates' => 0];
try {
    if (isset($opts['file'])) {                 // testing: parse a saved response instead
        $csv = file_get_contents($opts['file']);
        if ($csv === false) throw new Exception("Could not read {$opts['file']}");
        parseHourly($csv, $hours, $stats);
    } else {
        $y = (int)gmdate('Y', $start); $m = (int)gmdate('n', $start);
        $lastMonth = gmdate('Y-m', $end - 1);
        while (true) {
            $label = sprintf('%04d-%02d', $y, $m);
            parseHourly(fetchMonth($y, $m), $hours, $stats);
            if ($label === $lastMonth) break;
            if (++$m > 12) { $m = 1; $y++; }
        }
    }
} catch (Exception $e) {
    logError("Fetch failed, nothing written: " . $e->getMessage());
    exit(1);
}
ksort($hours);

// 2. Expand hours into 15-minute rows inside the window.
$rows = [];          // slotEndTs => inches
$hoursMissing = 0;
$hoursUsed = 0;
foreach ($hours as $hourEnd => $value) {
    $slotEnds = [];
    for ($k = SLOTS_PER_HOUR - 1; $k >= 0; $k--) $slotEnds[] = $hourEnd - $k * SLOT_SECONDS;
    $inWindow = array_values(array_filter($slotEnds, fn($t) => $t > $start && $t < $end));
    if (empty($inWindow)) continue;

    if ($value === null) { $hoursMissing++; continue; }
    $hoursUsed++;
    $parts = splitHour($value);
    foreach ($slotEnds as $k => $t) {
        if ($t > $start && $t < $end) $rows[$t] = $parts[$k];
    }
}

// Hours in the window with no report at all
$expectedHours = 0;
for ($h = (int)(floor($start / 3600) * 3600) + 3600; $h - 3 * SLOT_SECONDS < $end; $h += 3600) $expectedHours++;
$hoursAbsent = $expectedHours - $hoursUsed - $hoursMissing;

$total = array_sum($rows);
$summary = sprintf(
    "ASOS %s %s -> %s UTC: %d hours used, %d missing (M), %d absent, %d bad rows, %d duplicates; %d rows, total %.2f in",
    STATION, gmdate('Y-m-d H:i', $start), gmdate('Y-m-d H:i', $end),
    $hoursUsed, $hoursMissing, $hoursAbsent, $stats['bad_rows'], $stats['duplicates'], count($rows), $total
);

if ($dryRun) {
    $byMonth = []; $byDay = [];
    foreach ($rows as $t => $v) {
        $byMonth[gmdate('Y-m', $t)] = ($byMonth[gmdate('Y-m', $t)] ?? 0) + $v;
        $d = gmdate('Y-m-d', $t);
        $byDay[$d]['sum'] = ($byDay[$d]['sum'] ?? 0) + $v;
        $byDay[$d]['n']   = ($byDay[$d]['n'] ?? 0) + 1;
    }
    echo "Daily (UTC):\n";
    foreach ($byDay as $d => $x) printf("  %s  %5.2f in  (%d rows)\n", $d, $x['sum'], $x['n']);
    echo "Monthly (UTC):\n";
    foreach ($byMonth as $mo => $s) printf("  %s  %5.2f in\n", $mo, $s);
    echo $summary . "\n(dry run -- nothing written)\n";
    exit(0);
}

// 3. Replace the window in one transaction.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    require __DIR__ . '/db.inc';
    $mysqli = new mysqli($hostname, $db_username, $db_password, $db_name);
    $mysqli->begin_transaction();

    $startStr = gmdate('Y-m-d H:i:s', $start);
    $endStr   = gmdate('Y-m-d H:i:s', $end);
    $siteCode = SITE_CODE;

    $del = $mysqli->prepare(
        "DELETE FROM usgs_water_data_precipitation WHERE site = ? AND recorded_date > ? AND recorded_date < ?"
    );
    $del->bind_param('iss', $siteCode, $startStr, $endStr);
    $del->execute();
    $deleted = $del->affected_rows;
    $del->close();

    $ins = $mysqli->prepare(
        "INSERT INTO usgs_water_data_precipitation (recorded_date, site, value) VALUES (?, ?, ?)"
    );
    foreach ($rows as $t => $v) {
        $recordedDate = gmdate('Y-m-d H:i:s', $t);
        $value = $v;
        $ins->bind_param('sid', $recordedDate, $siteCode, $value);
        $ins->execute();
    }
    $ins->close();

    $mysqli->commit();
    $mysqli->close();
} catch (mysqli_sql_exception $e) {
    if (isset($mysqli)) { try { $mysqli->rollback(); } catch (Throwable $ignored) {} }
    logError("DB write failed, rolled back: " . $e->getMessage());
    exit(1);
}

logLine("$summary; replaced $deleted old rows");
exit(0);