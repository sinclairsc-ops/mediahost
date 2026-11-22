<?php

$file = '1.mp4';

$ffmpeg_output = shell_exec("c:\\ffmpeg\\bin\\ffmpeg.exe -i \"$file\" 2>&1");
if( preg_match('/.*Duration: ([0-9:]+).*/', $ffmpeg_output, $matches) ) {
	$time = explode(":", $matches[1]);
    echo $matches[1]." - ".($time[0]*3600+$time[1]*60+$time[2]);
} else {
    echo "$file failed\n";
}

?>