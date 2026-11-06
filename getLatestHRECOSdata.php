<?php

	$cso_rainfall_threshold = 0.3;
	$cso_duration_threshold = 8;

	if (isset($_GET["cso_rainfall_threshold"])) {
		$n = $_GET["cso_rainfall_threshold"];
		if (is_numeric($n)) {
			$cso_rainfall_threshold = (float)$n;
		}
	}
	if (isset($_GET["cso_duration_threshold"])) {
		$n = $_GET["cso_duration_threshold"];
		if (is_numeric($n)) {
			$cso_duration_threshold = (int)$n;
		}
	}

	// -------------------------------------------------------------------------
	// TIDE DATUM OFFSET
	//
	// HRECOS reports "depth" -- the water depth at the sonde -- not elevation
	// above a datum. USGS parameter 62620 (used until the Apr 2026 migration)
	// reported elevation above NAVD88. Subtracting this offset converts the
	// HRECOS depth reading back to NAVD88 feet so the tide chart matches the
	// historical series.
	//
	// !! THE SENSOR DRIFTS -- roughly 0.3 to 0.5 ft per month, apparently
	// !! stepping after each maintenance visit. RE-CHECK THIS MONTHLY:
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
	// !! ONLY VALID FOR DATES AFTER THE APR 2026 CHANGEOVER. Rows before that
	// !! are already NAVD88 and would be double-corrected. The tide queries
	// !! below look back 24 hours (or a week), so they never reach that far --
	// !! do not widen those windows past the changeover without revisiting this.
	//
	// Derived 2026-08-07 against NOAA Battery station 8518750.
	// -------------------------------------------------------------------------
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

    $pier_84_site_code = "01376515";
	$pier_25_site_code = "01376520";

    // all database dates are in UTC

    date_default_timezone_set('UTC');
	$now = date("Y-m-d H:i:s");
	$current_timestamp = strtotime($now);

    $hours_ago_2 = date_format((new DateTime())->modify('-2 hour'), "Y-m-d H:i:s");
	$hours_ago_4 = date_format((new DateTime())->modify('-4 hour'), "Y-m-d H:i:s");
	$hours_ago_8 = date_format((new DateTime())->modify('-8 hour'), "Y-m-d H:i:s");
	$hours_ago_12 = date_format((new DateTime())->modify('-12 hour'), "Y-m-d H:i:s");
	$hours_ago_24 = date_format((new DateTime())->modify('-24 hour'), "Y-m-d H:i:s");
	$hours_ago_48 = date_format((new DateTime())->modify('-48 hour'), "Y-m-d H:i:s");
	$last_week = date_format((new DateTime())->modify('-7 day'), "Y-m-d H:i:s");
	$two_weeks_ago = date_format((new DateTime())->modify('-14 day'), "Y-m-d H:i:s");

    $cumulative_2 = array();
	$cumulative_4 = array();
	$cumulative_8 = array();
	$cumulative_12 = array();
	$cumulative_24 = array();
	$cumulative_48 = array();

	$rainfall = array();

	require __DIR__ . "/db.inc";

	// Since PHP 8.1 mysqli throws on failure, so the old connect_errno check
	// could never fire -- a bad password produced a bare HTTP 500 instead.
	mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

	try {
		$mysqli = new mysqli($hostname, $db_username, $db_password, $db_name);
	} catch (Throwable $e) {
		// Deliberately not echoing $e->getMessage() -- it can leak connection
		// details. Check the server error log for the specifics.
		header('Content-Type: application/json');
		header('Access-Control-Allow-Origin: *');
		http_response_code(503);
		echo json_encode(array("error" => "database unavailable"));
		exit();
	}

	if ($mysqli->connect_errno) {
		exit();
	}

	$json_data = array();
	$json_data["asof"] = formatUTCtimestampToEST($now);
	$json_data["cso_rainfall_threshold"] = $cso_rainfall_threshold;
	$json_data["cso_duration_threshold"] = $cso_duration_threshold;
	$json_data["data"] = array(
		"site" => "01376515",
		"time" => formatUTCtimestampToEST($now)
	);

	// Weatherbit forecast

	$sql = "SELECT recorded_date as as_of, temp, description, code, wind_direction, wind_speed, sunrise, sunset, daytime FROM weatherbit_current_weather ORDER BY recorded_date desc LIMIT 1;";

	$result = $mysqli->query($sql);
	while ($row = $result->fetch_assoc()) {
		$json_data["data"]["weatherbit_forecast"] = array(
			"temp" => $row["temp"],
			"description" => $row["description"],
			"code" => $row["code"],
			"wind_direction" => $row["wind_direction"],
			"wind_speed" => $row["wind_speed"],
			"sunrise" => $row["sunrise"],
			"sunset" => $row["sunset"],
			"daytime" => ($row["daytime"] == 1),
			"as_of" => formatUTCtimestampToEST($row["as_of"])
		);
	}


	// forecast

	$sql = "SELECT recorded_date as as_of, name, forecast FROM nws_weather_forecast ORDER BY recorded_date desc LIMIT 1;";

	$result = $mysqli->query($sql);
	while ($row = $result->fetch_assoc()) {
		$json_data["data"]["forecast"] = array(
			"name" => $row["name"],
			"forecast" => $row["forecast"],
			"as_of" => formatUTCtimestampToEST($row["as_of"])
		);
	}


	// ph

	$sql = "SELECT recorded_date as as_of, value FROM usgs_water_data_ph WHERE value IS NOT NULL AND (site = '$pier_84_site_code' OR site = '$pier_25_site_code') ORDER BY recorded_date desc LIMIT 1;";

	$result = $mysqli->query($sql);
	while ($row = $result->fetch_assoc()) {
		$json_data["data"]["ph"] = array(
			"value" => floatval($row["value"]),
			"as_of" => formatUTCtimestampToEST($row["as_of"])
		);
	}

	// do_mpl

	$sql = "SELECT recorded_date as as_of, value FROM usgs_water_data_do_mpl WHERE value IS NOT NULL AND (site = '$pier_84_site_code' OR site = '$pier_25_site_code') ORDER BY recorded_date desc LIMIT 1;";

	$result = $mysqli->query($sql);
	while ($row = $result->fetch_assoc()) {
		$json_data["data"]["do_mpl"] = array(
			"value" => floatval($row["value"]),
			"as_of" => formatUTCtimestampToEST($row["as_of"])
		);
	}


	// do_percent

	$sql = "SELECT recorded_date as as_of, value FROM usgs_water_data_do_percent WHERE value IS NOT NULL AND (site = '$pier_84_site_code' OR site = '$pier_25_site_code') ORDER BY recorded_date desc LIMIT 1;";

	$result = $mysqli->query($sql);
	while ($row = $result->fetch_assoc()) {
		$json_data["data"]["do_percent"] = array(
			"value" => floatval($row["value"]),
			"as_of" => formatUTCtimestampToEST($row["as_of"])
		);
	}

	// turbidity

	$sql = "SELECT recorded_date as as_of, value FROM usgs_water_data_turbidity WHERE value IS NOT NULL AND (site = '$pier_84_site_code' OR site = '$pier_25_site_code') ORDER BY recorded_date desc LIMIT 1;";

	$result = $mysqli->query($sql);
	while ($row = $result->fetch_assoc()) {
		$json_data["data"]["turbidity"] = array(
			"value" => floatval($row["value"]),
			"as_of" => formatUTCtimestampToEST($row["as_of"])
		);
	}

	// water_temp

	$sql = "SELECT recorded_date as as_of, value * 9/5 + 32 as value FROM usgs_water_data_water_temp WHERE value IS NOT NULL AND (site = '$pier_84_site_code' OR site = '$pier_25_site_code') ORDER BY recorded_date desc LIMIT 1;";

	$result = $mysqli->query($sql);
	while ($row = $result->fetch_assoc()) {
		$json_data["data"]["water_temp"] = array(
			"value" => floatval($row["value"]),
			"as_of" => formatUTCtimestampToEST($row["as_of"])
		);
	}


	// air_temp

	$sql = "SELECT recorded_date as as_of, value FROM usgs_water_data_air_temp WHERE value IS NOT NULL AND (site = '$pier_84_site_code' OR site = '$pier_25_site_code') ORDER BY recorded_date desc LIMIT 1;";

	$result = $mysqli->query($sql);
	while ($row = $result->fetch_assoc()) {
		$json_data["data"]["air_temp"] = array(
			"value" => floatval($row["value"]),
			"as_of" => formatUTCtimestampToEST($row["as_of"])
		);
	}

	// humidity

	$sql = "SELECT recorded_date as as_of, value FROM usgs_water_data_humidity WHERE value IS NOT NULL AND (site = '$pier_84_site_code' OR site = '$pier_25_site_code') ORDER BY recorded_date desc LIMIT 1;";

	$result = $mysqli->query($sql);
	while ($row = $result->fetch_assoc()) {
		$json_data["data"]["humidity"] = array(
			"value" => floatval($row["value"]),
			"as_of" => formatUTCtimestampToEST($row["as_of"])
		);
	}

	// baro_pressure

	$sql = "SELECT recorded_date as as_of, value FROM usgs_water_data_baro_pressure WHERE value IS NOT NULL AND (site = '$pier_84_site_code' OR site = '$pier_25_site_code') ORDER BY recorded_date desc LIMIT 1;";

	$result = $mysqli->query($sql);
	while ($row = $result->fetch_assoc()) {
		$json_data["data"]["baro_pressure"] = array(
			"value" => floatval($row["value"]),
			"as_of" => formatUTCtimestampToEST($row["as_of"])
		);
	}

	// wind_speed

	$sql = "SELECT recorded_date as as_of, value FROM usgs_water_data_wind_speed WHERE value IS NOT NULL AND (site = '$pier_84_site_code' OR site = '$pier_25_site_code') ORDER BY recorded_date desc LIMIT 1;";

	$result = $mysqli->query($sql);
	while ($row = $result->fetch_assoc()) {
		$json_data["data"]["wind_speed"] = array(
			"value" => floatval($row["value"]),
			"as_of" => formatUTCtimestampToEST($row["as_of"])
		);
	}

	// wind_direction

	$sql = "SELECT recorded_date as as_of, value FROM usgs_water_data_wind_direction WHERE value IS NOT NULL AND (site = '$pier_84_site_code' OR site = '$pier_25_site_code') ORDER BY recorded_date desc LIMIT 1;";

	$result = $mysqli->query($sql);
	while ($row = $result->fetch_assoc()) {
		$json_data["data"]["wind_direction"] = array(
			"value" => floatval($row["value"]),
			"as_of" => formatUTCtimestampToEST($row["as_of"])
		);
	}

	// water_conductance

	$sql = "SELECT recorded_date as as_of, value FROM usgs_water_data_water_conductance WHERE value IS NOT NULL AND (site = '$pier_84_site_code' OR site = '$pier_25_site_code') ORDER BY recorded_date desc LIMIT 1;";

	$result = $mysqli->query($sql);
	while ($row = $result->fetch_assoc()) {
		$json_data["data"]["water_conductance"] = array(
			"value" => floatval($row["value"]),
			"as_of" => formatUTCtimestampToEST($row["as_of"])
		);
	}

	// salinity

	$sql = "SELECT recorded_date as as_of, value FROM usgs_water_data_salinity WHERE value IS NOT NULL AND (site = '$pier_84_site_code' OR site = '$pier_25_site_code') ORDER BY recorded_date desc LIMIT 1;";

	$result = $mysqli->query($sql);
	while ($row = $result->fetch_assoc()) {
		$json_data["data"]["salinity"] = array(
			"value" => floatval($row["value"]),
			"as_of" => formatUTCtimestampToEST($row["as_of"])
		);
	}

	// elevation (tide)
	//
	// CHANGED: subtract the datum offset, restrict to Pier 25 (the only station
	// producing depth readings), and drop sonde-out-of-water readings. The
	// "value > 1" test is against the RAW depth, before the offset is applied.

	$sql = "SELECT recorded_date as as_of, value - $tide_offset_sql as value FROM usgs_water_data_elevation WHERE value IS NOT NULL AND value > 1 AND site = $tide_site_code ORDER BY recorded_date desc LIMIT 1;";

	$result = $mysqli->query($sql);
	while ($row = $result->fetch_assoc()) {
		$json_data["data"]["elevation"] = array(
			"value" => floatval($row["value"]),
			"as_of" => formatUTCtimestampToEST($row["as_of"])
		);
	}

	// tides
	//
	// CHANGED: same three adjustments as above. NOTE the bound window is
	// $hours_ago_24 -- if you switch to $last_week that is still safely inside
	// the post-changeover era. Anything longer is NOT (see the offset comment).

	$sql = "SELECT recorded_date, value - $tide_offset_sql as elevation FROM usgs_water_data_elevation WHERE site = $tide_site_code AND recorded_date >= ? AND value IS NOT NULL AND value > 1 ORDER BY recorded_date";

	$json_data["data"]["tide_24hrs"] = array();

	if ($stmt = $mysqli->prepare($sql)) {
		$stmt->bind_param("s", $hours_ago_24);
		$stmt->execute();
		$stmt->bind_result($recorded_date, $elevation);
		while ($stmt->fetch()) {

			$json_data["data"]["tide_24hrs"][] = array(
				"elevation" => floatval($elevation),
				"time" => formatUTCtimestampToEST($recorded_date)
			);
		}
		$stmt->close();
	}


	// precipitation

	$sql = "SELECT recorded_date as as_of, value FROM usgs_water_data_precipitation WHERE value IS NOT NULL AND (site = '$pier_84_site_code' OR site = '$pier_25_site_code') ORDER BY recorded_date desc LIMIT 1;";

	$result = $mysqli->query($sql);
	while ($row = $result->fetch_assoc()) {
		$json_data["data"]["precipitation"] = array(
			"value" => floatval($row["value"]),
			"as_of" => formatUTCtimestampToEST($row["as_of"])
		);
	}


	// cumulative rainfall

	$sql = "SELECT sum(value) as rainfall FROM usgs_water_data_precipitation WHERE (site = '$pier_84_site_code' OR site = '$pier_25_site_code') AND recorded_date >= ? AND value IS NOT NULL";

	$cutoff = $hours_ago_2;

	if ($stmt = $mysqli->prepare($sql)) {
		$stmt->bind_param("s", $cutoff);
		$stmt->execute();
		$stmt->bind_result($rainfall);
		while ($stmt->fetch()) {
			$json_data["data"]["rainfall_last_2_hours"] = (is_null($rainfall) ? 0 : $rainfall);
		}

		$cutoff = $hours_ago_4;
		$stmt->execute();
		while ($stmt->fetch()) {
			$json_data["data"]["rainfall_last_4_hours"] = (is_null($rainfall) ? 0 : $rainfall);
		}

		$cutoff = $hours_ago_8;
		$stmt->execute();
		while ($stmt->fetch()) {
			$json_data["data"]["rainfall_last_8_hours"] = (is_null($rainfall) ? 0 : $rainfall);
		}

		$cutoff = $hours_ago_12;
		$stmt->execute();
		while ($stmt->fetch()) {
			$json_data["data"]["rainfall_last_12_hours"] = (is_null($rainfall) ? 0 : $rainfall);
		}

		$cutoff = $hours_ago_24;
		$stmt->execute();
		while ($stmt->fetch()) {
			$json_data["data"]["rainfall_last_24_hours"] = (is_null($rainfall) ? 0 : $rainfall);
		}

		$cutoff = $hours_ago_48;
		$stmt->execute();
		while ($stmt->fetch()) {
			$json_data["data"]["rainfall_last_48_hours"] = (is_null($rainfall) ? 0 : $rainfall);
		}

	}

	// Last CSO Event

	$rainfall = array();

	$sql = "SELECT recorded_date, value as precipitation FROM usgs_water_data_precipitation WHERE (site = '$pier_84_site_code' OR site = '$pier_25_site_code') AND recorded_date >= ? AND value IS NOT NULL ORDER BY recorded_date";

	if ($stmt = $mysqli->prepare($sql)) {
		$stmt->bind_param("s", $two_weeks_ago);
		$stmt->execute();
		$stmt->bind_result($recorded_date, $precipitation);

		while ($stmt->fetch()) {

			$timestamp = strtotime($recorded_date);
			$rainfall[] = array(
				"time" => $recorded_date,
				"timestamp" => $timestamp,
				"ago" => $current_timestamp - $timestamp,
				"precipitation" => $precipitation
			);

		}

	}

	$l = count($rainfall);

	$outer_index = $l - 1;

	$accumulated_rainfall = 0;
	$cso_found = false;
	$window_duration = $cso_duration_threshold * 60 * 60;

	while (($outer_index >= 0) && ($cso_found == false)) {

		$end_of_window = $rainfall[$outer_index]["ago"];
		$end_of_window_date = $rainfall[$outer_index]["time"];

		$inner_index = $outer_index;

		while (($inner_index >= 0) && ($cso_found == false) && (($rainfall[$inner_index]["ago"] - $end_of_window) <= $window_duration)) {
			$accumulated_rainfall += $rainfall[$inner_index]["precipitation"];
			$inner_index --;
		}

		if ($accumulated_rainfall >= $cso_rainfall_threshold) {
			$cso_found = true;
			$json_data["data"]["cso_event"] = array(
				"time" => formatUTCtimestampToEST($end_of_window_date),
				"rainfall" => round($accumulated_rainfall, 4),
				"hours_since" => round($end_of_window / 3600, 4)
			);
		} else {
			$accumulated_rainfall = 0;
			$outer_index --;
		}

	}

	header('Content-Type: application/json');
	header('Access-Control-Allow-Origin: *');
	header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
	header('Access-Control-Allow-Headers: Content-Type');
	header('Access-Control-Allow-Credentials: true');
	echo json_encode($json_data);