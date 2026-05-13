<?php
	
	
	
	date_default_timezone_set('America/New_York');
	
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
	
	
	function trace($message) {
		print $message . "\r\n";
	}
	
	function validNumber($s) {
		if (is_null($s)) {
			return false;
		}
		if (!is_numeric($s)) {
			return false;
		}
		if (is_nan($s)) {
			return false;
		}
		return true;
	}
	
	$now = date("Y-m-d H:i:s");
	$current_timestamp = strtotime($now);

	$d = new DateTime();

	$hours_ago_2 = date_format(($d)->modify('-2 hour'), "Y-m-d H:i:s");
	$hours_ago_4 = date_format(($d)->modify('-2 hour'), "Y-m-d H:i:s");
	$hours_ago_8 = date_format(($d)->modify('-4 hour'), "Y-m-d H:i:s");
	$hours_ago_12 = date_format(($d)->modify('-4 hour'), "Y-m-d H:i:s");
	$hours_ago_24 = date_format(($d)->modify('-12 hour'), "Y-m-d H:i:s");
	$hours_ago_48 = date_format(($d)->modify('-24 hour'), "Y-m-d H:i:s");

	$last_week = date_format(($d)->modify('-6 day'), "Y-m-d H:i:s");
	
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
	
	$sql = "SELECT site, recorded_date, ph, do_mpl, do_percent, turbidity, water_temp * 9/5 + 32 as water_temp, elevation, air_temp, humidity, baro_pressure, precipitation, wind_speed, wind_direction, water_conductance, salinity FROM usgs_water_data WHERE recorded_date >= ? ORDER BY recorded_date";
	
	$json_data = array();
	$json_data["asof"] = $now;
	$json_data["cso_rainfall_threshold"] = $cso_rainfall_threshold;
	$json_data["cso_duration_threshold"] = $cso_duration_threshold;
	$json_data["data"] = array();

	$siteData = array();
	$tideData = array();
	
	if ($stmt = $mysqli->prepare($sql)) {
		$stmt->bind_param("s", $last_week);
		$stmt->execute();
		$stmt->bind_result($site, $recorded_date, $ph, $do_mpl, $do_percent, $turbidity, $water_temp, $elevation, $air_temp, $humidity, $baro_pressure, $precipitation, $wind_speed, $wind_direction, $water_conductance, $salinity);
// 		$stmt->fetch();
		while ($stmt->fetch()) {
			
			if (!array_key_exists($site, $siteData)) {
				$siteData[$site] = array(
					"site" => $site
				);
			}

			$siteData[$site]["time"] = $recorded_date;
			
			if (validNumber($ph)) {
				$siteData[$site]["ph"] = $ph;				
			}
			if (validNumber($do_mpl)) {
				$siteData[$site]["do_mpl"] = $do_mpl;				
			}
			if (validNumber($do_percent)) {
				$siteData[$site]["do_percent"] = $do_percent;				
			}
			if (validNumber($turbidity)) {
				$siteData[$site]["turbidity"] = $turbidity;				
			}
			if (validNumber($water_temp)) {
				$siteData[$site]["water_temp"] = $water_temp;				
			}
			if (validNumber($elevation)) {
				$siteData[$site]["elevation"] = $elevation;				
			}
			if (validNumber($air_temp)) {
				$siteData[$site]["air_temp"] = $air_temp;				
			}
			if (validNumber($humidity)) {
				$siteData[$site]["humidity"] = $humidity;				
			}
			if (validNumber($baro_pressure)) {
				$siteData[$site]["baro_pressure"] = $baro_pressure;				
			}
			if (validNumber($precipitation)) {
				$siteData[$site]["precipitation"] = $precipitation;				
			}
			if (validNumber($wind_speed)) {
				$siteData[$site]["wind_speed"] = $wind_speed;				
			}
			if (validNumber($wind_direction)) {
				$siteData[$site]["wind_direction"] = $wind_direction;				
			}
			if (validNumber($water_conductance)) {
				$siteData[$site]["water_conductance"] = $water_conductance;				
			}
			if (validNumber($salinity)) {
				$siteData[$site]["salinity"] = $salinity;				
			}
			
			if (!array_key_exists($site, $cumulative_2)) {
				$cumulative_2[$site] = 0;
			}
			if (!array_key_exists($site, $cumulative_4)) {
				$cumulative_4[$site] = 0;
			}
			if (!array_key_exists($site, $cumulative_8)) {
				$cumulative_8[$site] = 0;
			}
			if (!array_key_exists($site, $cumulative_12)) {
				$cumulative_12[$site] = 0;
			}
			if (!array_key_exists($site, $cumulative_24)) {
				$cumulative_24[$site] = 0;
			}
			if (!array_key_exists($site, $cumulative_48)) {
				$cumulative_48[$site] = 0;
			}
			
			if ($recorded_date >= $hours_ago_2) {
				$cumulative_2[$site] += $precipitation;
			}
			if ($recorded_date >= $hours_ago_4) {
				$cumulative_4[$site] += $precipitation;
			}
			if ($recorded_date >= $hours_ago_8) {
				$cumulative_8[$site] += $precipitation;
			}
			if ($recorded_date >= $hours_ago_12) {
				$cumulative_12[$site] += $precipitation;
			}
			if ($recorded_date >= $hours_ago_24) {
				$cumulative_24[$site] += $precipitation;
			}
			if ($recorded_date >= $hours_ago_48) {
				$cumulative_48[$site] += $precipitation;
			}
			if (!is_null($elevation)) {
				if ($recorded_date >= $hours_ago_24) {
					if (!array_key_exists($site, $tideData)) {
						$tideData[$site] = array();
					}
					$tideData[$site][] = array(
						"time" => $recorded_date,
						"elevation" => $elevation
					);
				}
			}
			
			if (!array_key_exists($site, $rainfall)) {
				$rainfall[$site] = array();
			}
			
			
			$timestamp = strtotime($recorded_date);
			$rainfall[$site][] = array(
				"time" => $recorded_date,
				"timestamp" => $timestamp,
				"ago" => $current_timestamp - $timestamp,
				"precipitation" => $precipitation
			);
			
		}	
		$stmt->close();
	}
	
	
	$mysqli->close();
	
	foreach($siteData as $site => $data) {
		$data["rainfall_last_2_hours"] = $cumulative_2[$site];
		$data["rainfall_last_4_hours"] = $cumulative_4[$site];
		$data["rainfall_last_8_hours"] = $cumulative_8[$site];
		$data["rainfall_last_12_hours"] = $cumulative_12[$site];
		$data["rainfall_last_24_hours"] = $cumulative_24[$site];
		$data["rainfall_last_48_hours"] = $cumulative_48[$site];
		if (array_key_exists($site, $tideData)) {
			$data["tide_24hrs"] = $tideData[$site];
		}
		
			
		// how long since the last CSO event?
		
		$l = count($rainfall[$site]);
		
		$outer_index = $l - 1;
		
		$accumulated_rainfall = 0;
		$cso_found = false;
		$window_duration = $cso_duration_threshold * 60 * 60;

		while (($outer_index >= 0) && ($cso_found == false)) {
			
			$end_of_window = $rainfall[$site][$outer_index]["ago"];
			$end_of_window_date = $rainfall[$site][$outer_index]["time"];
			
// 			trace("Testing period ending " . $end_of_window_date);
			
			$inner_index = $outer_index;
			
			while (($inner_index >= 0) && ($cso_found == false) && (($rainfall[$site][$inner_index]["ago"] - $end_of_window) <= $window_duration)) {
				$accumulated_rainfall += $rainfall[$site][$inner_index]["precipitation"];
				if ($accumulated_rainfall >= $cso_rainfall_threshold) {
					$cso_found = true;
					$data["cso_event"] = array(
						"time" => $end_of_window_date,
						"rainfall" => round($accumulated_rainfall, 4),
						"hours_since" => round($end_of_window / 3600, 4)
					);
				}
				$inner_index --;
			}

/*
			trace("Accumulated rainfall for window " . $accumulated_rainfall);
			if ($cso_found) {
				trace("CSO found in the period ending $end_of_window_date. Seconds ago: $end_of_window");
			}
*/
			
			$accumulated_rainfall = 0;
			$outer_index --;
	
			}
				
		$json_data["data"][] = $data;
	}
	
// 	$json_data["rainfall"] = $rainfall;
	
	header('Content-Type: application/json');	
	header('Access-Control-Allow-Origin: *');
	header('Access-Control-Allow-Methods: GET');
	
	echo json_encode($json_data);
	

