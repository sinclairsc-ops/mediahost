<?php

//Подключается к БД и возвращает текстовое сообщение с количеством посетителей для информера

if ($conn=OCILogon("kurort", "kurortt", "//192.168.12.10:1521/ora01"))

{
//  echo "Successfully connected to Oracle.\n";

	$date = date("d.m.Y");
	$time = date("H:i");

	$sql_query = "select TO_CHAR(localtimestamp,'DD.MM.YYYY') as cd, TO_CHAR(localtimestamp,'HH24:MI') as ct, sum(result) as re from
(
select 
  -count(*) as result
from 
  (
  select 
    ZFI_MEDIA_NUM, 
    ZFI_ORDER_ID, 
    ZFI_ZONE_ID, 
    ZFI_DEVICE_ID, 
    ZFI_CARD_TYPE_ID, 
    SUM(ZFI_IN_COUNTER) as ZFI_IN_COUNTER, 
    SUM(ZFI_OUT_COUNTER) as ZFI_OUT_COUNTER 
  from
    ZONE_FILL_INFO 
  where 
    ZFI_DATE between TO_DATE('$date 00:00', 'dd.mm.yyyy hh24:mi') and TO_DATE('$date $time', 'dd.mm.yyyy hh24:mi')
    and 
    ZFI_OUT_COUNTER>0
  group by 
    ZFI_MEDIA_NUM, 
    ZFI_ORDER_ID, 
    ZFI_ZONE_ID, 
    ZFI_DEVICE_ID, 
    ZFI_CARD_TYPE_ID
  ) 
  where 
    ZFI_CARD_TYPE_ID<>7 
  group by 
    ZFI_ZONE_ID
union all
select 
  count(*) as result
from
  (
    select 
      ZFI_MEDIA_NUM, 
      ZFI_ORDER_ID, 
      ZFI_ZONE_ID, 
      ZFI_DEVICE_ID, 
      ZFI_CARD_TYPE_ID, 
      SUM(ZFI_IN_COUNTER) as ZFI_IN_COUNTER, 
      SUM(ZFI_OUT_COUNTER) as ZFI_OUT_COUNTER 
    from 
      ZONE_FILL_INFO 
    where 
      ZFI_DATE between TO_DATE('$date 00:00', 'dd.mm.yyyy hh24:mi') and TO_DATE('$date $time', 'dd.mm.yyyy hh24:mi')
      and
      ZFI_IN_COUNTER>0 
    group by 
      ZFI_MEDIA_NUM, 
      ZFI_ORDER_ID, 
      ZFI_ZONE_ID, 
      ZFI_DEVICE_ID, 
      ZFI_CARD_TYPE_ID
  )
where
  ZFI_CARD_TYPE_ID<>7
group by
  ZFI_ZONE_ID
)";


	$stid = oci_parse($conn, $sql_query);
	oci_execute($stid);

	$i = 0;
	while ($row = oci_fetch_array($stid, OCI_ASSOC+OCI_RETURN_NULLS)) {
		foreach ($row as $item)
		{
			$t[$i++] = $item;
		}
	}


  OCILogoff($conn);
} else {
  $err = OCIError();
//  echo "Oracle Connect Error " . $err[text];

	$ip = $_SERVER['REMOTE_ADDR'];
	$logname = "log/log_".date("Y-m-d")."_[$ip].log";
	$file = fopen($logname, "a");

	for($i = 0; $i < count($log); $i++)
		fwrite($file, date("[H:i:s] ")."1/1 Oracle Connect Error " . $err[text]."\r\n");
	fclose($file);

return;
}

$curdate =$t[0];
$curtime =$t[1];
if(count($t)>2)
  $curcount=trim($t[2]);
else
  $curcount=0;

echo ("$curdate<br>на $curtime в Аквапарке<br>".format_text($curcount));


function format_text($num)
{
	if(strlen($num) < 1 || !$num)
		return "нет посетителей";

	switch(substr($num, -2, 1))
	{
	case "1":
		if ($num == 1)
			return "$num посетитель";
		return "$num посетителей";
	break;
	default:
		switch(substr($num, -1))
		{
			case "0":
				return "$num посетителей";
			break;
			case "1":
				return "$num посетитель";
			break;
			case "2":
				return "$num посетителя";
			break;
			case "3":
				return "$num посетителя";
			break;
			case "4":
				return "$num посетителя";
			break;
			case "5":
				return "$num посетителей";
			break;
			case "6":
				return "$num посетителей";
			break;
			case "7":
				return "$num посетителей";
			break;
			case "8":
				return "$num посетителей";
			break;
			case "9":
				return "$num посетителей";
			break;
			default:
			break;
		}
		break;
	}

}

?>