<?php
/**
 * NWS Weather Forecast Cron Fetcher
 *
 * Fetches the current NWS weather forecast for the NYC area (gridpoint
 * OKX/33,37) and stores the first forecast period in the nws_weather_forecast
 * table.
 *
 * Intended to run on a cron. The forecast doesn't change often, so every
 * 15-30 minutes is reasonable.
 *
 */

// -----------------------------------------------------------------------------
// Configuration
// -----------------------------------------------------------------------------

// Gridpoint OKX/33,37 is the NYC area, served by the Upton NY weather office.
const NWS_FORECAST_URL = 'https://api.weather.gov/gridpoints/OKX/33,37/forecast';

// NWS requires a User-Agent header on every request. They prefer
// "AppName/version (contact@example.com)" but accept any non-empty string.
const NWS_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.3';

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

/**
 * Parse any ISO 8601 timestamp (e.g., "2026-05-13T10:30:00-04:00") and return
 * a UTC datetime string suitable for MySQL DATETIME.
 */
function iso8601ToUTC($datestring) {
    try {
        $dt = new DateTime($datestring);
    } catch (Exception $e) {
        return null;
    }
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Y-m-d H:i:s');
}

function logLine($msg) {
    echo "[" . gmdate('Y-m-d H:i:s') . " UTC] " . $msg . "\n";
}

function logError($msg) {
    fwrite(STDERR, "[" . gmdate('Y-m-d H:i:s') . " UTC] ERROR: " . $msg . "\n");
}

// -----------------------------------------------------------------------------
// DB connection
// -----------------------------------------------------------------------------
require "db.inc";

$mysqli = new mysqli($hostname, $db_username, $db_password, $db_name);
if ($mysqli->connect_errno) {
    logError("DB connection failed: " . $mysqli->connect_error);
    exit(1);
}

// -----------------------------------------------------------------------------
// Fetch and store
// -----------------------------------------------------------------------------
$handle = curl_init();
curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
curl_setopt($handle, CURLOPT_TIMEOUT, 60);
curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 15);
curl_setopt($handle, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($handle, CURLOPT_URL, NWS_FORECAST_URL);
curl_setopt($handle, CURLOPT_USERAGENT, NWS_USER_AGENT);

$exitCode = 0;

try {
    $raw = curl_exec($handle);
    if ($raw === false) {
        throw new Exception("cURL error: " . curl_error($handle));
    }
    $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
    if ($httpCode !== 200) {
        throw new Exception("HTTP $httpCode");
    }

    $json = json_decode($raw, true);
    if (!is_array($json) || !isset($json['properties']['updateTime'], $json['properties']['periods'][0])) {
        throw new Exception("Unexpected response shape");
    }

    $updated  = iso8601ToUTC($json['properties']['updateTime']);
    $name     = $json['properties']['periods'][0]['name'] ?? null;
    $forecast = $json['properties']['periods'][0]['detailedForecast'] ?? null;

    if (is_null($updated) || is_null($name) || is_null($forecast)) {
        throw new Exception("Missing required fields in forecast");
    }

    $sql = "INSERT INTO nws_weather_forecast (recorded_date, `name`, forecast) VALUES (?, ?, ?)"
         . " ON DUPLICATE KEY UPDATE `name` = ?, forecast = ?";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $mysqli->error);
    }

    $stmt->bind_param("sssss", $updated, $name, $forecast, $name, $forecast);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new Exception("execute failed: " . $err);
    }
    $stmt->close();

    logLine("NWS forecast updated: $updated ($name)");

} catch (Exception $e) {
    logError("NWS fetch failed: " . $e->getMessage());
    $exitCode = 1;
}

// -----------------------------------------------------------------------------
// Cleanup
// -----------------------------------------------------------------------------
curl_close($handle);
$mysqli->close();

exit($exitCode);