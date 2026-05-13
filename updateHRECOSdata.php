<?php
	
function newYorkTimeToUTC($datestring) {
	
	// Create a DateTime object with the time string and its original timezone
	$dateTime = new DateTime($datestring, new DateTimeZone('America/New_York'));
	
	// Change the timezone of the object to UTC
	$dateTime->setTimezone(new DateTimeZone('UTC'));
	
	// Format the DateTime object to a string in your desired output format
	$utcTimeString = $dateTime->format('Y-m-d\TH:i:s\Z');
	
	// Output the UTC time string
	return $utcTimeString;
}	


$verbose = false;

require "db.inc";

$mysqli = new mysqli($hostname, $db_username, $db_password, $db_name);

if ($mysqli->connect_errno) {
    exit("Could not connect to db");
}

// Site codes

// 	pier84: 01376515
// 	pier26: 01376520

// Parameter codes

// 00010: water_temp
// 00020: air_temp
// 00036: wind_direction
// 00045: precipitation
// 00052: humidity
// 00095: water_conductance
// 00300: do_mpl
// 00301: do_percent
// 00400: ph
// 62620: elevation
// 63680: turbidity
// 75969: baro_pressure
// 82127: wind_speed
// 90860: salinity

// $start_date (and $end_date) format: "2023-02-01T00:00-0500"

date_default_timezone_set('America/New_York');
$today = new DateTime();
$yesterday = $today->sub(new DateInterval("P1D"));
$start_date = $yesterday->format('Y-m-d\TH:iO');
$parameters = ["00010", "00020", "00036", "00045", "00052", "00095", "00300", "00301", "00400", "62620", "63680", "75969", "82127", "90860"];
// $site = "01376515";
$site = "01376520";


$handle = curl_init();
curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);

	
foreach ($parameters as $parameter) {
    
   	switch ($parameter) {
	   	case "00010":
	   		$table = "usgs_water_data_water_temp";
	   		break;
	   	case "00020":
	   		$table = "usgs_water_data_air_temp";
	   		break;
	   	case "00036":
	   		$table = "usgs_water_data_wind_direction";
	   		break;
	   	case "00045":
	   		$table = "usgs_water_data_precipitation";
	   		break;
	   	case "00052":
	   		$table = "usgs_water_data_humidity";
	   		break;
	   	case "00095":
	   		$table = "usgs_water_data_water_conductance";
	   		break;
	   	case "00300":
	   		$table = "usgs_water_data_do_mpl";
	   		break;
	   	case "00301":
	   		$table = "usgs_water_data_do_percent";
	   		break;
	   	case "00400":
	   		$table = "usgs_water_data_ph";
	   		break;
	   	case "62620":
	   		$table = "usgs_water_data_elevation";
	   		break;
	   	case "63680":
	   		$table = "usgs_water_data_turbidity";
	   		break;
	   	case "75969":
	   		$table = "usgs_water_data_baro_pressure";
	   		break;
	   	case "82127":
	   		$table = "usgs_water_data_wind_speed";
	   		break;
	   	case "90860":
	   		$table = "usgs_water_data_salinity";
	   		break;
	   	
   	}
    
    // $url = "https://nwis.waterservices.usgs.gov/nwis/iv/?format=rdb&sites=01376515&startDT=" . $start_date . "&endDT=" . $end_date . "&parameterCd=00400,00300,00301,63680,00010,62620,00020,00052,75969,00045,82127,00095,90860&siteStatus=all";
    // $url = "https://nwis.waterservices.usgs.gov/nwis/iv/?format=json&sites=" . $site . "&startDT=" . $start_date . "&parameterCd=00400,00300,00301,63680,00010,62620,00020,00052,75969,00045,82127,00095,90860,00036&siteStatus=all";
    // $url = "https://nwis.waterservices.usgs.gov/nwis/iv/?format=json&sites=" . $site . "&startDT=" . $start_date . "&endDT=" . $end_date . "&parameterCd=00400,00300,00301,63680,00010,62620,00020,00052,75969,00045,82127,00095,90860,00036&siteStatus=all";
    // $url = "https://nwis.waterservices.usgs.gov/nwis/iv/?format=json&sites=" . $site . "&startDT=" . $start_date . "&endDT=" . $end_date . "&parameterCd=" . $parameter . "&siteStatus=all";

    $url = "https://nwis.waterservices.usgs.gov/nwis/iv/?format=json&sites=" . $site . "&startDT=" . $start_date . "&parameterCd=" . $parameter . "&siteStatus=all";

    if ($verbose) {
        echo "<br/><br/><pre>Fetching $url<br/>Start date: $start_date</pre><br/>";
    }

    curl_setopt($handle, CURLOPT_URL, $url);

    $raw_json = curl_exec($handle);

    $json = json_decode($raw_json, true);

    $allTimeSeries = $json["value"]["timeSeries"];
    
    $query = "INSERT INTO " . $table . " (recorded_date, site, value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = ?";

    if ($verbose) {
        echo "<br/><br/>query $query<br/>";
    }
    
    if ($upsert_statement = $mysqli->prepare($query)) {	


        foreach ($allTimeSeries as $timeSeries) {

            $siteCode = $timeSeries["sourceInfo"]["siteCode"][0]["value"];
            $variableCode = $timeSeries["variable"]["variableCode"][0]["value"];
            $variableName = $timeSeries["variable"]["variableName"];
            $data = $timeSeries["values"][0]["value"];

            if ($verbose) {
                echo "Processing $variableCode - $variableName<br/>";
            }

            foreach ($data as $datapoint) {
                $value = $datapoint["value"];
                $dateTime = newYorkTimeToUTC($datapoint["dateTime"]);

                $upsert_statement->bind_param("sddd", $dateTime, $siteCode, $value, $value);
                $upsert_statement->execute();

                if ($verbose) {
	                $numRowsUpdated = $upsert_statement->affected_rows;
                    echo "$variableCode - $dateTime - $siteCode - $value rows: $numRowsUpdated<br/>";
                }

            }

        }
    }
    $upsert_statement->close();

}



if ($upsert_statement = $mysqli->prepare("INSERT INTO nws_weather_forecast (recorded_date, `name`, forecast) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `name` = ?, forecast = ?")) {

    $url = "https://api.weather.gov/gridpoints/OKX/33,37/forecast";

    curl_setopt($handle, CURLOPT_URL, $url);

    $userAgent = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.3";
    curl_setopt($handle, CURLOPT_USERAGENT, $userAgent);    

    $raw_json = curl_exec($handle);

    // echo "<br>Raw json<br/>$raw_json<br/>";

    $json = json_decode($raw_json, true);

    // echo "<br/>json<br/>$json<br/>";

    $updated = newYorkTimeToUTC($json["properties"]["updateTime"]);

    $name = $json["properties"]["periods"][0]["name"];
    $forecast = $json["properties"]["periods"][0]["detailedForecast"];


    $upsert_statement->bind_param("sssss", $updated, $name, $forecast, $name, $forecast);
    $upsert_statement->execute();
    
	if ($verbose) {
		$numRowsUpdated = $upsert_statement->affected_rows;
    	echo "Weather forecast updated: $updated name: $name forecast: $forecast rows: $numRowsUpdated<br/>";
    }
    
}
$upsert_statement->close();

$mysqli->close();
curl_close($handle);

if ($verbose) {
    echo "Done";
}

exit();
