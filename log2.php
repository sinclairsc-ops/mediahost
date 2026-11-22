<?php
// Сохраняет сообщение в лог. Разделяет сгруппированные сообщения

if(isset($_POST['logtext']))
	$logtext = $_POST["logtext"];

$counter = 0;
if(isset($_POST['counter']))
	$counter = $_POST["counter"];

//echo $counter;

if($counter)
	$log = explode("||||", $logtext);
else
	$log[0] = $logtext;

//print_r($logtext);

$ip = $_SERVER['REMOTE_ADDR'];

$logname = "log/log_".date("Y-m-d")."_[$ip].log";
$file = fopen($logname, "a");

for($i = 0; $i < count($log); $i++)
	if($counter)
		fwrite($file, date("[H:i:s] ")." log2: ".($i+1)."/".($counter)." {".$log[$i]."}\r\n");
	else
		fwrite($file, date("[H:i:s] ")." log2: ".($counter)." {".$logtext."}\r\n");
fclose($file);

?>