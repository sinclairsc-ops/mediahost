<html>
<head>
<meta charset="utf-8">
<title>Тест</title>
</head>
<body>

<?php

$s_time = time();

echo $s_time."<br>\n";

function FormatErrors( $errors )
{
echo "Error information: <br/>";

foreach ( $errors as $error )
{
echo "SQLSTATE: ".$error['SQLSTATE']."<br/>";
echo "Code: ".$error['code']."<br/>";
echo "Message: ".$error['message']."<br/>";
}
}

$sched = file("schedule.txt");

$serverName = "192.168.12.6\\SQLSERVER2005"; //если instance и port стандартные, то можно не указывать
$connectionInfo = array("UID" => "backup", "PWD" => "backup", "Database"=>"Orion","CharacterSet" =>"UTF-8" /*,"ConnectionPooling" => "1", "MultipleActiveResultSets"=>'0'*/);
$conn = sqlsrv_connect( $serverName, $connectionInfo);
 
if( $conn )
{
     echo "Connection established.\n<br><br>";
}
else
{
     echo "Connection could not be established.\n<br><br>";
     die( FormatErrors( sqlsrv_errors()));
}

$correction = "";

echo "<table style=\"border: 1px solid;\">\n";
echo "<tr>\n";
echo "<td>Дата</td>\n<td>Приход по<br>расписанию</td>\n<td>Приход<br>фактически</td>\n<td>Уход по<br>расписанию</td>\n<td>Уход<br>фактически</td>\n<td>Номер события</td>\n<td>Точка прихода</td>\n<td>Номер события</td>\n<td>Точка ухода</td>\n";
echo "</tr>\n";

for($i = 0; $i < count($sched); $i++)
{
	$schedule[$i] = explode(";",$sched[$i]);

	$cur_date = date_create($schedule[$i][1]." 00:00:00");
	if($cur_date < date_create('now'))
	{

		echo "<tr>\n";
		echo "<td>".$schedule[$i][1]."</td>\n";
		$day_start = date_create($schedule[$i][1]." 00:00:00");
		$day_end   = date_create($schedule[$i][1]." 23:59:59");
		$sql = "select min(TimeVal) as minimum, max(TimeVal) as maximum from plogdata where TimeVal>='".date_format($day_start, 'm-d-Y H:i:s')."' and TimeVal<='".date_format($day_end, 'm-d-Y H:i:s')."' and hozorgan=".$schedule[$i][0];
//		echo "\n".$sql."\n";
		$stmt = sqlsrv_query( $conn, $sql, array(), array( "Scrollable" => SQLSRV_CURSOR_STATIC ) );
		if( $stmt === false) {
				die( print_r( sqlsrv_errors(), true) );
		}

	//		sqlsrv_free_stmt( $stmt);
		while( $row = sqlsrv_fetch_array( $stmt, SQLSRV_FETCH_ASSOC) )
		{
			$min = $row['minimum']; $max = $row['maximum'];
			$cor_min = $cor_max = 0;
			if($min != null and $max != null)
			{
				$minimum = date_create($schedule[$i][1]." ".$schedule[$i][2]);
				$maximum = date_create($schedule[$i][1]." ".$schedule[$i][3]);
				if($min > $minimum)
				{
					echo "<td style=\"color: red;\">".date_format($minimum, 'H:i:s')."</td>\n<td style=\"color: red;\">".date_format($min, 'H:i:s')."</td>\n";
					$cor_min = (date_timestamp_get($min) - date_timestamp_get($minimum)) % 600;
				}
				else
					echo "<td>".date_format($minimum, 'H:i:s')."</td>\n<td>".date_format($min, 'H:i:s')."</td>\n";
				if($max < $maximum)
				{
					echo "<td style=\"color: red;\">".date_format($maximum, 'H:i:s')."</td>\n<td style=\"color: red;\">".date_format($max, 'H:i:s')."</td>\n";
					$cor_max = (date_timestamp_get($max) - date_timestamp_get($maximum)) % 600;
				}
				else
					echo "<td>".date_format($maximum, 'H:i:s')."</td>\n<td>".date_format($max, 'H:i:s')."</td>\n";
			}
		}

		sqlsrv_free_stmt( $stmt);

		if($min != null and $max != null)
		{

			$sql = "select NumCom, Remark from plogdata where TimeVal='".date_format($min, 'Y-m-d H:i:s')."' and hozorgan=".$schedule[$i][0];
			$stmt = sqlsrv_query( $conn, $sql, array(), array( "Scrollable" => SQLSRV_CURSOR_FORWARD ) );
			if( $stmt === false) {
					die( print_r( sqlsrv_errors(), true) );
			}

			while( $row = sqlsrv_fetch_array( $stmt, SQLSRV_FETCH_ASSOC) ) {
						echo "<td>".$row['NumCom']."</td>\n<td>".$row['Remark']."</td>\n";
						$nt = date_create($schedule[$i][1]." ".$schedule[$i][2]);
						$nt = date_timestamp_set($nt, date_timestamp_get($nt) - $cor_min);
						$newtime = date_format($nt, 'Y-m-d H:i:s');
						if($cor_min) $correction .= "update plogdata set TimeVal = '".$newtime."', devicetime='".$newtime."' where numcom=".$row['NumCom']."\n<br>\n";
			}
			sqlsrv_free_stmt( $stmt);

			$sql = "select NumCom, Remark from plogdata where TimeVal='".date_format($max, 'Y-m-d H:i:s')."' and hozorgan=".$schedule[$i][0];
			$stmt = sqlsrv_query( $conn, $sql, array(), array( "Scrollable" => SQLSRV_CURSOR_FORWARD ) );
			if( $stmt === false) {
					die( print_r( sqlsrv_errors(), true) );
			}

			while( $row = sqlsrv_fetch_array( $stmt, SQLSRV_FETCH_ASSOC) ) {
						echo "<td>".$row['NumCom']."</td>\n<td>".$row['Remark']."</td>\n";
						$nt = date_create($schedule[$i][1]." ".$schedule[$i][3]);
						$nt = date_timestamp_set($nt, date_timestamp_get($nt) - $cor_max);
						$newtime = date_format($nt, 'Y-m-d H:i:s');
						if ($cor_max) $correction .= "update plogdata set TimeVal = '".$newtime."', devicetime='".$newtime."' where numcom=".$row['NumCom']."\n<br>\n";
			}
			echo "</tr>\n";
			sqlsrv_free_stmt( $stmt);
	}
	}
}

echo "</table>\n";
$e_time = time();

echo $e_time."\n";

echo $e_time-$s_time;

echo "<br><br>\n".$correction;

//var_dump($conn);
 
/* Close the connection. */
sqlsrv_close( $conn);



?>
</body>
</html>