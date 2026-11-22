<?php

// Формирует плейлист для телевизоров в зависимости от даты
// Плейлисты загружаются из json-файла, где указаны даты и время начала и окончания действия плейлиста, и выбирает из него подходящий.
// Если на конкретную дату и время будет действовать несколько плейлистов, будет выбран последний.
// Параметр SanitaryDay нужен для указания, будет понедельник сонитарным днём или нет.
// Выбранный плейлист возвращается скрипту на телевизоре в виде JSON

$playlist = file_get_contents ("playlist.json");


$shedule = json_decode($playlist);

$current_time = new DateTime('NOW');
//$current_time = new DateTime('2021-01-04T12:03:01');

$current_playlist['Playlist'] = ["1.mp4", "2.mp4", "3.mp4", "4.mp4", "5.mp4", "6.mp4"];
$current_playlist['SanitaryDay'] = (($current_time->format('N') == 1)?true:false);

for($i = 0; $i < count($shedule); $i++)
{

	$playlist_start = DateTime::createFromFormat('d.m.Y H:i', $shedule[$i]->playlist_start);
	$playlist_end   = DateTime::createFromFormat('d.m.Y H:i', $shedule[$i]->playlist_end);


//echo "now: ".$current_time->format('d.m.Y H:i')." start:".$playlist_start->format('d.m.Y H:i')." end:".$playlist_end->format('d.m.Y H:i')."\n";
//echo "now: ".$current_time->format('U')." start:".$playlist_start->format('U')." end:".$playlist_end->format('U')."\n";

	if($current_time >= $playlist_start && $current_time < $playlist_end)
	{

//echo "matched\n";

		$current_playlist['Playlist'] = $shedule[$i]->playlist;
		$current_playlist['SanitaryDay'] = (($shedule[$i]->sanitaryday & ($current_time->format('N') == 1))?true:false);
		$current_playlist['GeneralWatchdog'] = isset($shedule[$i]->watchdog)?$shedule[$i]->watchdog:15;
	}
	
}


echo json_encode($current_playlist);

?>