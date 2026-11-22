<?php

// Вторая версия формирования плейлиста
// Плейлисты загружаются из json-файла, где указаны даты и время начала и окончания действия плейлиста, и выбирает из него подходящий.
// Если на конкретную дату и время будет действовать несколько плейлистов, будет выбран последний.
// Параметр SanitaryDay нужен для указания, будет понедельник сонитарным днём или нет.
// В плейлисте указываются основные ролики в секции main - они проигрываются по порядку, и дополнительные ролики в секции additional - они проигрываются между основными в случайном порядке
// Выбранный ролик возвращается как плейлист возвращается скрипту на телевизоре в виде JSON



function GenerateWatchDog($file)  // функция рассчитывает время главного сторожевого таймера для скрипта на телевизоре в минутах, в зависимости от длины ролика
{

//$ffmpeg_path = 'c:\\ffmpeg\\bin\\ffmpeg.exe'; //or: /usr/bin/ffmpeg , or /usr/local/bin/ffmpeg - depends on your installation (type which ffmpeg into a console to find the install path)
$ffmpeg_path = '/usr/bin/ffmpeg'; //or: /usr/bin/ffmpeg , or /usr/local/bin/ffmpeg - depends on your installation (type which ffmpeg into a console to find the install path)

$ffmpeg_output = shell_exec("$ffmpeg_path -i \"$file\" 2>&1");
if( preg_match('/.*Duration: ([0-9:]+).*/', $ffmpeg_output, $matches) )
{
	$time = explode(":", $matches[1]);
    return round(($time[0]*3600+$time[1]*60+$time[2]) / 60 + 1.5);
}
else
    return 3;
	
}


//считываем текущее состояние проигрывания для IP клиента
$ip = $_SERVER['REMOTE_ADDR'];
$statefile = "state_$ip.dat";
if(file_exists($statefile))
{
	$curstatefile = file_get_contents($statefile);
	$curstate = unserialize($curstatefile);
}
else
{$curstate[0] = 0; $curstate[1] = 0;}


//читаем файл плейлистов
$playlist = file_get_contents ("playlist_new.json");

//и декодируем
$shedule = json_decode($playlist);


//берём текущие время и дату для поиска нужного плейлиста
$current_time = new DateTime('NOW');
//if($ip=="10.202.77.24")
//  $current_time = new DateTime('2022-01-10T12:03:01');

//заполняем данные на случай, если ничего не найдём
$current_playlist['Playlist'] = ["1.mp4", "2.mp4", "3.mp4", "4.mp4", "5.mp4", "6.mp4"];
$current_playlist['SanitaryDay'] = (($current_time->format('N') == 1)?true:false);
$base_playlist['additional'] = array();
$maintenance = false;

$sanitaryday_playlist['Playlist'] = ["s_day.mp4"];

//ищем последний подходящий по времени плейлист в файле
for($i = 0; $i < count($shedule); $i++)
{
	$playlist_start = DateTime::createFromFormat('d.m.Y H:i', $shedule[$i]->playlist_start);
	$playlist_end   = DateTime::createFromFormat('d.m.Y H:i', $shedule[$i]->playlist_end);

	if($current_time >= $playlist_start && $current_time < $playlist_end)
	{
		
		if(property_exists($shedule[$i], 'maintenance') and $shedule[$i]->maintenance)
			$maintenance = true;
		else
			$maintenance = false;
			
		if(property_exists($shedule[$i], 'main'))
			$base_playlist['main'] = $shedule[$i]->main;
		if(property_exists($shedule[$i], 'additional'))
			$base_playlist['additional'] = $shedule[$i]->additional;
		else
		  $base_playlist['additional'] = array();
		
		
		if(property_exists($shedule[$i], 'sanitaryday'))
		{
			if($shedule[$i]->sanitaryday & ($current_time->format('N') == 1))
			{
				$base_playlist['main'] = $sanitaryday_playlist['Playlist'];
				$curstate[1] = 0;
				$maintenance = true;
			}
//			else
//				$current_playlist['SanitaryDay'] = (($shedule[$i]->sanitaryday & ($current_time->format('N') == 1))?true:false);
		}
//		else
	
		$current_playlist['SanitaryDay'] = false;
		
		
		$current_playlist['GeneralWatchdog'] = 3; //isset($shedule[$i]->watchdog)?$shedule[$i]->watchdog:15;

/*
		else
		{
			$maintenance = false;
			$base_playlist['main'] = $shedule[$i]->main;
		}
	}	
*/
	}
}


//переменная, отвечающая за то, проигрываем основной ролик или дополнительный
$curstate[1] = $curstate[1]?0:1;

//если играем дополнительный, проверим есть ли он, и выберем его
if($curstate[1] == 0 and count($base_playlist['additional']))
{
	$current_playlist['Playlist'] = array();
	$additionalcnt = rand(0, count($base_playlist['additional'])-1);
	$current_playlist['Playlist'][0] = $base_playlist['additional'][$additionalcnt];
}
else
{
//тут выбираем основной (по очереди)
	if($curstate[0] >= count($base_playlist['main'])) $curstate[0] = 0;
	$current_playlist['Playlist'] = array();
	$current_playlist['Playlist'][0] = $base_playlist['main'][$curstate[0]];
	$curstate[0]++;
}

if($maintenance) $current_playlist['maintenance'] = true;
//для выбранного ролика вычислим адекватное время общего сторожевого таймера для скрипта на телевизоре
$current_playlist['GeneralWatchdog'] = $current_playlist['SanitaryDay']?4:GenerateWatchDog($current_playlist['Playlist'][0]);



//сохраним состояние для текущего телевизора, чтобы при следующем обращении выдать следующий ролик
file_put_contents($statefile, serialize($curstate));

//вернём сформированный плейлист
echo json_encode($current_playlist);

?>