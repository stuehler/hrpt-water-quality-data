<?php
	
	function validateNumber($s) {
		if (!isset($s)) {
			return null;
		}
		if (is_null($s)) {
			return null;
		}
		if ($s == "") {
			return null;
		}
		if (!is_numeric($s)) {
			return null;
		}
		if (is_nan($s)) {
			return null;
		}
		$value = (float)$s;
		if (!is_double($value) && !is_integer($value)) {
			return null;
		}
		return $value;
	}
	function validateString($s) {
		if (!isset($s)) {
			return null;
		}
		if (is_null($s)) {
			return null;
		}
		if (!is_string($s)) {
			return null;
		}
		$s = trim($s);
		if ($s == "") {
			return null;
		}
		return $s;
	}
	function trace($msg) {
		echo ("<br>\n" . $msg);
	}
	
	$new_rows = 0;
	
 	require "db.inc";
	
	$mysqli = new mysqli($hostname, $db_username, $db_password, $db_name);
	
	if ($mysqli->connect_errno) {
		exit();
	}
	
	$handle = curl_init();
	curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);

	$site_list = ["01376515", "01376520"];
	for ($k = 0; $k < count($site_list); $k ++) {
		
		$processing_site = $site_list[$k];
		
		trace ("processing_site: " . $processing_site);
		
		$start_date = null;

		if ($stmt = $mysqli->prepare("SELECT DATE_FORMAT(DATE_ADD(max(recorded_date), INTERVAL 1 MINUTE), '%Y-%m-%dT%H:%i') AS start_date FROM usgs_water_data WHERE site = ?")) {
			$stmt->bind_param("s", $processing_site);
			$stmt->execute();
			$stmt->bind_result($start_date);
			$stmt->fetch();
			$stmt->close();
		}
		
		if (is_null($start_date)) {
// 			$start_date = "2020-06-01T12:00-0500";
			$start_date = "2020-05-31T23:59-0500";
		} else {
			$start_date = $start_date . "-0500";
		}
		
		trace ("start_date: " . $start_date);
		
		
/*
		$result = $mysqli->query("SELECT DATE_FORMAT(DATE_ADD(max(recorded_date), INTERVAL 1 MINUTE), '%Y-%m-%dT%H:%i-0500') AS start_date FROM usgs_water_data");
		
		while ($row = $result->fetch_assoc()) {
			$start_date = $row["start_date"];
		}
*/
		
	
		if (!is_null($start_date)) {

// 			print_r($start_date);
			
		// 	pier84 site: 01376515
		// 	pier26 site: 01376520
		
			
		// 	$start_date = "2021-01-01T00:00-0500";
		// 	$end_date = "2020-12-31T23:59-0500";
			 
		// 	$url = "https://nwis.waterservices.usgs.gov/nwis/iv/?format=rdb&sites=01376515&startDT=" . $start_date . "&endDT=" . $end_date . "&parameterCd=00400,00300,00301,63680,00010,62620,00020,00052,75969,00045,82127,00095,90860&siteStatus=all";
			$url = "https://nwis.waterservices.usgs.gov/nwis/iv/?format=rdb&sites=" . $processing_site . "&startDT=" . $start_date . "&parameterCd=00400,00300,00301,63680,00010,62620,00020,00052,75969,00045,82127,00095,90860,00036&siteStatus=all";

			trace ("url: " . $url);
			
			 
			// Set the url
			curl_setopt($handle, CURLOPT_URL, $url);
			// Set the result output to be a string.
			 
			$rdb = curl_exec($handle);
			 
			
			unset($agency_cd_index);
			unset($site_no_index);
			unset($datetime_index);
			unset($water_temp_index);
			unset($air_temp_index);
			unset($precipitation_index);
			unset($humidity_index);
			unset($water_conductance_index);
			unset($do_mpl_index);
			unset($do_percent_index);
			unset($ph_index);
			unset($elevation_index);
			unset($turbidity_index);
			unset($baro_pressure_index);
			unset($wind_speed_index);
			unset($wind_direction_index);
			unset($salinity_index);

			$row_data = explode("\n", $rdb);
			
			$rows = count($row_data);
			
			for ($i = 0; $i < $rows; $i ++) {
				$row = trim($row_data[$i]);
				if (substr($row, 0, 4) != "#") {
					if (substr($row, 0, 9) == "agency_cd") {
						$headers = explode("\t", $row);
						for ($j = 0; $j < count($headers); $j ++) {
							$header = trim($headers[$j]);
							if ($header == "agency_cd") {
								$agency_cd_index = $j;
							} else if ($header == "site_no") {
								$site_no_index = $j;
							} else if ($header == "datetime") {
								$datetime_index = $j;
							} else if (substr($header, -6) == "_00010") {
								$water_temp_index = $j;
							} else if (substr($header, -6) == "_00020") {
								$air_temp_index = $j;
							} else if (substr($header, -6) == "_00045") {
								$precipitation_index = $j;
							} else if (substr($header, -6) == "_00052") {
								$humidity_index = $j;
							} else if (substr($header, -6) == "_00095") {
								$water_conductance_index = $j;
							} else if (substr($header, -6) == "_00300") {
								$do_mpl_index = $j;
							} else if (substr($header, -6) == "_00301") {
								$do_percent_index = $j;
							} else if (substr($header, -6) == "_00400") {
								$ph_index = $j;
							} else if (substr($header, -6) == "_62620") {
								$elevation_index = $j;
							} else if (substr($header, -6) == "_63680") {
								$turbidity_index = $j;
							} else if (substr($header, -6) == "_75969") {
								$baro_pressure_index = $j;
							} else if (substr($header, -6) == "_82127") {
								$wind_speed_index = $j;
							} else if (substr($header, -6) == "_00036") {
								$wind_direction_index = $j;
							} else if (substr($header, -6) == "_90860") {
								$salinity_index = $j;
							}
						}
						
						$first_row = $i + 2;
						break; 
					}
				}
			}
			
/*
			trace("agency_cd_index: " . $agency_cd_index);
			trace("site_no_index: " . $site_no_index);
			trace("datetime_index: " . $datetime_index);
			trace("water_temp_index: " . $water_temp_index);
			trace("air_temp_index: " . $air_temp_index);
			trace("precipitation_index: " . $precipitation_index);
			trace("humidity_index: " . $humidity_index);
			trace("water_conductance_index: " . $water_conductance_index);
			trace("do_mpl_index: " . $do_mpl_index);
			trace("do_percent_index: " . $do_percent_index);
			trace("ph_index: " . $ph_index);
			trace("elevation_index: " . $elevation_index);
			trace("turbidity_index: " . $turbidity_index);
			trace("baro_pressure_index: " . $baro_pressure_index);
			trace("wind_speed_index: " . $wind_speed_index);
			trace("wind_direction_index: " . $wind_direction_index);
			trace("salinity_index: " . $salinity_index);
*/
			
			
			$json = array();
			
			
			if (isset($first_row)) {
				for ($i = $first_row; $i < $rows; $i ++) {
					$row = trim($row_data[$i]);
					$data = explode("\t", $row);
					if (count($data) >= 10) {
						if (isset($site_no_index)) {
							$site = validateString($data[$site_no_index]);
						} else {
							$site = null;
						}
						if (($site == "01376515") || ($site == "01376520")) {
							$valid = 0;
							$record = array();
							$record["site"] = $site;
							if (isset($datetime_index)) {
								$record["recorded_date"] = validateString($data[$datetime_index]);
								if (!is_null($record["recorded_date"])) {
									$valid ++;
								}
							}
							if (isset($water_temp_index)) {
								$record["water_temp"] = validateNumber($data[$water_temp_index]);
								if (!is_null($record["water_temp"])) {
									$valid ++;
								}
							}
							if (isset($air_temp_index)) {
								$record["air_temp"] = validateNumber($data[$air_temp_index]);
								if (!is_null($record["air_temp"])) {
									$valid ++;
								}
							}
							if (isset($precipitation_index)) {
								$record["precipitation"] = validateNumber($data[$precipitation_index]);
								if (!is_null($record["precipitation"])) {
									$valid ++;
								}
							}
							if (isset($humidity_index)) {
								$record["humidity"] = validateNumber($data[$humidity_index]);
								if (!is_null($record["humidity"])) {
									$valid ++;
								}
							}
							if (isset($water_conductance_index)) {
								$record["water_conductance"] = validateNumber($data[$water_conductance_index]);
								if (!is_null($record["water_conductance"])) {
									$valid ++;
								}
							}
							if (isset($do_mpl_index)) {
								$record["do_mpl"] = validateNumber($data[$do_mpl_index]);
								if (!is_null($record["do_mpl"])) {
									$valid ++;
								}
							}
							if (isset($do_percent_index)) {
								$record["do_percent"] = validateNumber($data[$do_percent_index]);
								if (!is_null($record["do_percent"])) {
									$valid ++;
								}
							}
							if (isset($ph_index)) {
								$record["ph"] = validateNumber($data[$ph_index]);
								if (!is_null($record["ph"])) {
									$valid ++;
								}
							}
							if (isset($elevation_index)) {
								$record["elevation"] = validateNumber($data[$elevation_index]);
								if (!is_null($record["elevation"])) {
									$valid ++;
								}
							}
							if (isset($turbidity_index)) {
								$record["turbidity"] = validateNumber($data[$turbidity_index]);
								if (!is_null($record["turbidity"])) {
									$valid ++;
								}
							}
							if (isset($baro_pressure_index)) {
								$record["baro_pressure"] = validateNumber($data[$baro_pressure_index]);
								if (!is_null($record["baro_pressure"])) {
									$valid ++;
								}
							}
							if (isset($wind_speed_index)) {
								$record["wind_speed"] = validateNumber($data[$wind_speed_index]);
								if (!is_null($record["wind_speed"])) {
									$valid ++;
								}
							}
							if (isset($wind_direction_index)) {
								$record["wind_direction"] = validateNumber($data[$wind_direction_index]);
								if (!is_null($record["wind_direction"])) {
									$valid ++;
								}
							}
							if (isset($salinity_index)) {
								$record["salinity"] = validateNumber($data[$salinity_index]);
								if (!is_null($record["salinity"])) {
									$valid ++;
								}
							}
// 							trace ("row: " . $i . " valid: " . $valid);
							if ($valid >= 5) {
								$json[] = $record;
							}
						}
					}
				}
			}
		
			if ($stmt = $mysqli->prepare("INSERT INTO usgs_water_data (site, recorded_date, ph, do_mpl, do_percent, turbidity, water_temp, elevation, air_temp, humidity, baro_pressure, precipitation, wind_speed, wind_direction, water_conductance, salinity) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")) {
				$l = count($json);
				for ($i = 0; $i < $l; $i++) {
					$o = $json[$i];
					$stmt->bind_param("ssdddddddddddddd", $o["site"], $o["recorded_date"], $o["ph"], $o["do_mpl"], $o["do_percent"], $o["turbidity"], $o["water_temp"], $o["elevation"], $o["air_temp"], $o["humidity"], $o["baro_pressure"], $o["precipitation"], $o["wind_speed"], $o["wind_direction"], $o["water_conductance"], $o["salinity"]);
					$stmt->execute();
					$new_rows ++;
				}
				$stmt->close();
			}
		
// 			print_r($json);

		}
	}

	curl_close($handle);
	$mysqli->close();

/*
	$l = count($json);
	for ($i = 0; $i < $l; $i++) {
		$o = $json[$i];
		echo ("<p>" . $o["site"]. ", " . $o["recorded_date"]. ", " . $o["ph"]. ", " . $o["do_mpl"]. ", " . $o["do_percent"]. ", " . $o["turbidity"]. ", " . $o["water_temp"]. ", " . $o["elevation"]. ", " . $o["air_temp"]. ", " . $o["humidity"]. ", " . $o["baro_pressure"]. ", " . $o["precipitation"]. ", " . $o["wind_speed"]. ", " . $o["water_conductance"]. ", " . $o["salinity"] . "</p>");
	}
*/


trace ("New rows: " . $new_rows);
exit();	
