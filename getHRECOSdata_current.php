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
	
	require "db.inc";
	
	$mysqli = new mysqli($hostname, $db_username, $db_password, $db_name);
	
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
	
	$sql = "SELECT recorded_date as as_of, value FROM usgs_water_data_elevation WHERE value IS NOT NULL AND (site = '$pier_84_site_code' OR site = '$pier_25_site_code') ORDER BY recorded_date desc LIMIT 1;";
	
	$result = $mysqli->query($sql);
	while ($row = $result->fetch_assoc()) {
		$json_data["data"]["elevation"] = array(
			"value" => floatval($row["value"]),
			"as_of" => formatUTCtimestampToEST($row["as_of"])
		);
	}	
	
	// tides
	
	$sql = "SELECT recorded_date, value as elevation FROM usgs_water_data_elevation WHERE (site = '$pier_84_site_code' OR site = '$pier_25_site_code') AND recorded_date >= ? AND value IS NOT NULL ORDER BY recorded_date";
	
	$json_data["data"]["tide_24hrs"] = array();
	
	if ($stmt = $mysqli->prepare($sql)) {
		$stmt->bind_param("s", $hours_ago_24);
		$stmt->execute();
		$stmt->bind_result($recorded_date, $elevation);
		while ($stmt->fetch()) {
			
			$json_data["data"]["tide_24hrs"][] = array(
				"elevation" => $elevation,
				"time" => formatUTCtimestampToEST($recorded_date)
			);
		}
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
