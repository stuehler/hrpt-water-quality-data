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

function formatUTCtimezoneString($datestring) {
	
	// Create a DateTime object with the time string and its original timezone
	$dateTime = new DateTime($datestring, new DateTimeZone('UTC'));
	
	// Format the DateTime object to a string in your desired output format
	$utcTimeString = $dateTime->format('Y-m-d\TH:i:s\Z');
	
	// Output the UTC time string
	return $utcTimeString;
}


function celsiusToFahrenheit($celsius) {
    $fahrenheit = ($celsius * 9/5) + 32;
    return $fahrenheit;
}


function formatSunriseAndSunset($utc_time) {

    $dateTime = new DateTime("now", new DateTimeZone('America/New_York'));

    // Get the offset in seconds and convert it to hours
    $offset = $dateTime->getOffset() / 3600;
    
    $parts = $utc_time = explode(":", $utc_time);
    
    $hh = $parts[0];
    $mm = $parts[1];
    
    $mm = str_pad($mm, 2, "0", STR_PAD_LEFT);
    
    if ($hh > 12) {
	    $hh -= 12;
    }
    
    $hh += $offset;
    
    if ($hh < 0) {
	    $hh += 12;
    }
    
    return "$hh:$mm";
}

$verbose = false;

require "db.inc";

$mysqli = new mysqli($hostname, $db_username, $db_password, $db_name);

if ($mysqli->connect_errno) {
    exit("Could not connect to db");
}


date_default_timezone_set('America/New_York');


$handle = curl_init();
curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);

if ($upsert_statement = $mysqli->prepare("INSERT INTO weatherbit_current_weather (recorded_date, temp, description, code, wind_direction, wind_speed, sunrise, sunset, daytime) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE temp = ?, description = ?, code = ?, wind_direction = ?, wind_speed = ?, sunrise = ?, sunset = ?, daytime = ?")) {

    //$url = "https://api.weatherbit.io/v2.0/current?key=4a43ce0f6b8047d7b0ffb3ec1faed8a9&lat=40.71427&lon=-74.00597&units=I";
    $url = "https://api.weatherbit.io/v2.0/current?key=ec3774483d734ff8a75afb8006974bc7&lat=40.764202&lon=-74.001935&units=I";

    curl_setopt($handle, CURLOPT_URL, $url);

    $userAgent = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.3";
    curl_setopt($handle, CURLOPT_USERAGENT, $userAgent);    


	$raw_json = curl_exec($handle);
/*
	$raw_json = '{
    "count": 1,
    "data": [
        {
            "app_temp": 64.7,
            "aqi": 33,
            "city_name": "New York City",
            "clouds": 2,
            "country_code": "US",
            "datetime": "2024-04-16:15",
            "dewpt": 33.2,
            "dhi": 114.83,
            "dni": 891.35,
            "elev_angle": 50.4,
            "ghi": 794.26,
            "gust": null,
            "h_angle": -15,
            "lat": 40.7143,
            "lon": -74.006,
            "ob_time": "2024-04-16 15:00",
            "pod": "m",
            "precip": 0,
            "pres": 1020.4,
            "rh": 31,
            "slp": 1020.5,
            "snow": 0,
            "solar_rad": 794.3,
            "sources": [
                "WTCN6",
                "radar",
                "satellite"
            ],
            "state_code": "NY",
            "station": "WTCN6",
            "sunrise": "10:13",
            "sunset": "23:37",
            "temp": 64.6,
            "timezone": "America/New_York",
            "ts": 1713279600,
            "uv": 6.6323004,
            "vis": 9.9,
            "weather": {
                "description": "Clear sky",
                "code": 800,
                "icon": "c01d"
            },
            "wind_cdir": "WSW",
            "wind_cdir_full": "west-southwest",
            "wind_dir": 244,
            "wind_spd": 2.3
        }
    ]
}';
*/

    $json = json_decode($raw_json, true);
    
    $data = $json["data"][0];

    $recorded_date = formatUTCtimezoneString($data["ob_time"]);
//     $temp = celsiusToFahrenheit($data["temp"]);
    $temp = $data["temp"];
    $description = $data["weather"]["description"];
    $code = $data["weather"]["code"];
    $wind_direction = $data["wind_cdir"];
    $wind_speed = $data["wind_spd"];
    $sunrise = formatSunriseAndSunset($data["sunrise"]);
    $sunset = formatSunriseAndSunset($data["sunset"]);
    $daytime = ($data["pod"] == "d") ? 1 : 0;

    $upsert_statement->bind_param("sssssssssssssssss", $recorded_date, $temp, $description, $code, $wind_direction, $wind_speed, $sunrise, $sunset, $daytime, $temp, $description, $code, $wind_direction, $wind_speed, $sunrise, $sunset, $daytime);
    $upsert_statement->execute();

	if ($verbose) {
		$numRowsUpdated = $upsert_statement->affected_rows;
	    echo "<br/>recorded_date<br/>$recorded_date<br/>";
	    echo "<br/>temp<br/>$temp<br/>";
	    echo "<br/>description<br/>$description<br/>";
	    echo "<br/>code<br/>$code<br/>";
	    echo "<br/>wind_direction<br/>$wind_direction<br/>";
	    echo "<br/>wind_speed<br/>$wind_speed<br/>";
	    echo "<br/>sunrise<br/>$sunrise<br/>";
	    echo "<br/>sunset<br/>$sunset<br/>";
	    echo "<br/>daytime<br/>$daytime<br/>";
    	echo "Weather forecast updated rows: $numRowsUpdated<br/>";
    }
    
}
$upsert_statement->close();

$mysqli->close();
curl_close($handle);

if ($verbose) {
    echo "Done";
}

exit();
