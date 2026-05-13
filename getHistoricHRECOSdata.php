<?php

function formatUTCtimestampToEST($utc_date_string)
{
    $timestamp = strtotime($utc_date_string);
    $date = new DateTime();
    $date->setTimestamp($timestamp);
    $date->setTimezone(new DateTimeZone("America/New_York"));
    // return $date->format('Y-m-d\TH:iO');
    return $date->format("Y-m-d H:i:s");
}

require "db.inc";

$mysqli = new mysqli($hostname, $db_username, $db_password, $db_name);

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


$column = $_GET["measure"];

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

    $sql =  "SELECT value, DATE_FORMAT(recorded_date, '%Y-%m-%d %H:%i:00') as date FROM usgs_water_data_elevation WHERE (site=? OR site=?) AND recorded_date >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND value IS NOT NULL  ORDER BY recorded_date";

    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param("ss", $site, $alt_site);
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
    } else if ($column == "elevation") {
        $sql = "SELECT value, DATE_FORMAT(recorded_date, '%Y-%m-%d %H:%i:00') as date FROM usgs_water_data_elevation WHERE (site=? OR site=?) AND recorded_date >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND value IS NOT NULL ORDER BY recorded_date";
    } else {
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

header('Content-Type: application/json');	
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');
echo json_encode($json_data);
