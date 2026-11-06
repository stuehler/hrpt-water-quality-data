<?php

// -----------------------------------------------------------------------------
// TIDE DATUM OFFSET  -- keep this value in sync with the "latest data" endpoint
//
// HRECOS reports "depth" -- the water depth at the sonde -- not elevation above
// a datum. USGS parameter 62620 (used until the Apr 2026 migration) reported
// elevation above NAVD88. Subtracting this offset converts the HRECOS depth
// reading back to NAVD88 feet so the tide chart matches the historical series.
//
// !! THE SENSOR DRIFTS -- roughly 0.3 to 0.5 ft per month, apparently stepping
// !! after each maintenance visit. RE-CHECK THIS MONTHLY:
//
//     SELECT ROUND(AVG(value) - 0.21, 2) AS suggested_offset, COUNT(*) n
//     FROM usgs_water_data_elevation
//     WHERE site = 1376520
//       AND recorded_date >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 28 DAY)
//       AND value > 1;
//
// (the 0.21 is the 2025 summer mean elevation at Pier 25; it is seasonal --
//  in winter use roughly -0.4 instead)
//
// !! ONLY VALID FOR DATES AFTER THE APR 2026 CHANGEOVER. Rows before that are
// !! already NAVD88 and would be double-corrected. The elevation query below
// !! looks back 7 days, which is safely inside the post-changeover era. DO NOT
// !! widen it to the 1 YEAR window the other measures use without revisiting
// !! this -- that window straddles the changeover and the offset would be
// !! applied to rows that do not need it.
//
// Derived 2026-08-07 against NOAA Battery station 8518750.
// -----------------------------------------------------------------------------
$tide_datum_offset = 12.5;
$tide_offset_sql   = sprintf('%.4F', $tide_datum_offset); // locale-safe for SQL

// Only Pier 25 / HPIER carries the depth-based readings the offset applies to.
$tide_site_code = 1376520;


function formatUTCtimestampToEST($utc_date_string)
{
    $timestamp = strtotime($utc_date_string);
    $date = new DateTime();
    $date->setTimestamp($timestamp);
    $date->setTimezone(new DateTimeZone("America/New_York"));
    // return $date->format('Y-m-d\TH:iO');
    return $date->format("Y-m-d H:i:s");
}

function sendJsonHeaders()
{
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Credentials: true');
}

// -----------------------------------------------------------------------------
// Validate ?measure=
//
// SECURITY: $column was previously interpolated straight into the FROM clause
// as "usgs_water_data_$column", which made this endpoint SQL-injectable via the
// query string. It was also read without isset(), so a missing parameter threw
// on PHP 8. Both are fixed by checking against this list.
//
// Add new measures here when you add new tables.
// -----------------------------------------------------------------------------
$valid_measures = array(
    "air_temp",
    "baro_pressure",
    "chla",
    "do_mpl",
    "do_percent",
    "elevation",
    "humidity",
    "par",
    "ph",
    "phyco",
    "precipitation",
    "precipitation_24hrs",
    "salinity",
    "sdwdir",
    "turbidity",
    "water_conductance",
    "water_temp",
    "wind_direction",
    "wind_speed"
);

$column = isset($_GET["measure"]) ? $_GET["measure"] : "";

if (!in_array($column, $valid_measures, true)) {
    sendJsonHeaders();
    http_response_code(400);
    echo json_encode(array("data" => array(), "error" => "unknown measure"));
    exit();
}

require __DIR__ . "/db.inc";

// Since PHP 8.1 mysqli throws on failure, so the old connect_errno check could
// never fire -- a bad password produced a bare HTTP 500 instead.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $mysqli = new mysqli($hostname, $db_username, $db_password, $db_name);
} catch (Throwable $e) {
    // Deliberately not echoing $e->getMessage() -- it can leak connection
    // details. Check the server error log for the specifics.
    sendJsonHeaders();
    http_response_code(503);
    echo json_encode(array("data" => array(), "error" => "database unavailable"));
    exit();
}

if ($mysqli->connect_errno) {
    exit();
}


/*
if (isset($_GET["site"])) {
    $site = $_GET["site"];
} else {
    $site = "01376515";
}
*/
$site = "01376515";
$alt_site = "01376520";


$json_data["data"] = array();

if ($column == "do_mpl") {

    // The DO chart compares DO to water temp, so return both data sets

    // get water_temp

    $sql = "SELECT round(AVG(value * 9/5 + 32), 5) AS value, DATE_FORMAT(recorded_date, '%Y-%m-%d %H:00:00') as dateAndHour FROM usgs_water_data_water_temp WHERE (site=? OR site=?) AND recorded_date >= DATE_SUB(NOW(), INTERVAL 1 YEAR) AND value IS NOT NULL GROUP BY dateAndHour ORDER BY recorded_date";

    $water_temp_by_timestamp = array();
    $timestamps = array();
    $do_mpl_by_timestamp = array();
    $unique = array();

    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param("ss", $site, $alt_site);
        $stmt->execute();

        $stmt->bind_result($water_temp, $recorded_date);

        while ($stmt->fetch()) {
	        $time = formatUTCtimestampToEST($recorded_date);
			if (!isset($unique[$time])) {
				$unique[$time] = true;
				$timestamps[] = $time;
			}
	        $water_temp_by_timestamp[$time] = round($water_temp, 4);
        }
        $stmt->close();

    }

    // get do_mpl

    $sql = "SELECT round(AVG(value), 5) AS do_mpl, DATE_FORMAT(recorded_date, '%Y-%m-%d %H:00:00') as dateAndHour FROM usgs_water_data_do_mpl WHERE (site=? OR site=?) AND recorded_date >= DATE_SUB(NOW(), INTERVAL 1 YEAR) AND value IS NOT NULL GROUP BY dateAndHour ORDER BY recorded_date";

    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param("ss", $site, $alt_site);
        $stmt->execute();

        $stmt->bind_result($do_mpl, $recorded_date);

        while ($stmt->fetch()) {
	        $time = formatUTCtimestampToEST($recorded_date);
			if (!isset($unique[$time])) {
				$unique[$time] = true;
				$timestamps[] = $time;
			}
	        $do_mpl_by_timestamp[$time] = round($do_mpl, 4);
        }

        $stmt->close();

    }

	sort($timestamps);

	$l = count($timestamps);

	for ($i = 0; $i < $l; $i++) {
		$timestamp = $timestamps[$i];

		if (isset($water_temp_by_timestamp[$timestamp])) {
			$water_temp = $water_temp_by_timestamp[$timestamp];
		} else {
			$water_temp = null;
		}

		if (isset($do_mpl_by_timestamp[$timestamp])) {
			$do_mpl = $do_mpl_by_timestamp[$timestamp];
		} else {
			$do_mpl = null;
		}

		if (!is_null($water_temp) && !is_null($do_mpl)) {
			$json_data["data"][] = array(
				"time" => $timestamp,
				"do_mpl" => $do_mpl,
				"water_temp" => $water_temp
			);
		} else if (!is_null($water_temp)) {
			$json_data["data"][] = array(
				"time" => $timestamp,
				"water_temp" => $water_temp
			);
		} else if (!is_null($do_mpl)) {
			$json_data["data"][] = array(
				"time" => $timestamp,
				"do_mpl" => $do_mpl
			);
		}
	}

} else if ($column == "elevation") {

    // The Elevation (tide) chart compares elevation to salinity temp, so return both data sets

    // get elevation
    //
    // CHANGED: subtract the datum offset, restrict to Pier 25 (the only station
    // producing depth readings), and drop sonde-out-of-water readings. The
    // "value > 1" test is against the RAW depth, before the offset is applied.
    // The site is now a fixed constant, so there is nothing left to bind.

    $sql =  "SELECT value - $tide_offset_sql, DATE_FORMAT(recorded_date, '%Y-%m-%d %H:%i:00') as date FROM usgs_water_data_elevation WHERE site = $tide_site_code AND recorded_date >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND value IS NOT NULL AND value > 1 ORDER BY recorded_date";

    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->execute();

        $stmt->bind_result($elevation, $recorded_date);

        $json_data["data"] = array();

        while ($stmt->fetch()) {
            $json_data["data"][] = array(
                "time" => formatUTCtimestampToEST($recorded_date),
                "elevation" => round($elevation, 4)
            );
        }
        $stmt->close();

    }

} else {

    if ($column == "water_temp") {
        $sql = "SELECT round(AVG(value * 9/5 + 32), 5) AS value, DATE_FORMAT(recorded_date, '%Y-%m-%d %H:00:00') as dateAndHour FROM usgs_water_data_water_temp WHERE (site=? OR site=?) AND recorded_date >= DATE_SUB(NOW(), INTERVAL 1 YEAR) AND value IS NOT NULL GROUP BY dateAndHour ORDER BY recorded_date";
    } else if ($column == "precipitation") {
        $sql = "SELECT round(SUM(value), 5) AS value, DATE_FORMAT(recorded_date, '%Y-%m-%d %H:00:00') as dateAndHour FROM usgs_water_data_precipitation WHERE (site=? OR site=?) AND recorded_date >= DATE_SUB(NOW(), INTERVAL 1 YEAR) AND value IS NOT NULL GROUP BY dateAndHour ORDER BY recorded_date";
    } else if ($column == "precipitation_24hrs") {
        $sql = "SELECT round(SUM(value), 5) AS value, DATE_FORMAT(recorded_date, '%Y-%m-%d %00:00:00') as dateAndHour FROM usgs_water_data_precipitation WHERE (site=? OR site=?) AND recorded_date >= DATE_SUB(NOW(), INTERVAL 1 YEAR) AND value IS NOT NULL GROUP BY dateAndHour ORDER BY recorded_date";
    } else {
        // NOTE: the original had an "elevation" branch here too. It was
        // unreachable -- the outer "else if ($column == "elevation")" above
        // always caught it first -- and it did NOT apply the datum offset.
        // Removed so there is only one place elevation is queried.
        //
        // $column is whitelist-validated above, so this interpolation is safe.
        $table = "usgs_water_data_$column";
        $sql = "SELECT round(AVG(value), 5) AS value, DATE_FORMAT(recorded_date, '%Y-%m-%d %H:00:00') as dateAndHour FROM $table WHERE (site=? OR site=?) AND recorded_date >= DATE_SUB(NOW(), INTERVAL 1 YEAR) AND value IS NOT NULL GROUP BY dateAndHour ORDER BY recorded_date";
    }

    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param("ss", $site, $alt_site);
        $stmt->execute();
        $stmt->bind_result($value, $recorded_date);
        while ($stmt->fetch()) {
            $json_data["data"][] = array(
                "time" => formatUTCtimestampToEST($recorded_date),
                $column => round($value, 4)
            );
        }
        $stmt->close();
    }

}

$mysqli->close();

sendJsonHeaders();
echo json_encode($json_data);