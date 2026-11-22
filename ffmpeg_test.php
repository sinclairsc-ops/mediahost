<?php
$ffmpeg_path = 'c:\\ffmpeg\\bin\\ffmpeg.exe'; //or: /usr/bin/ffmpeg , or /usr/local/bin/ffmpeg - depends on your installation (type which ffmpeg into a console to find the install path)
$vid = '1.mp4'; //Replace here!

 if (file_exists($vid)) {

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $vid); // check mime type
    finfo_close($finfo);

    if (preg_match('/video\/*/', $mime_type)) {

        $video_attributes = _get_video_attributes($vid, $ffmpeg_path);

        print_r('Codec: ' . $video_attributes['codec'] . '<br/>');

        print_r('Dimension: ' . $video_attributes['width'] . ' x ' . $video_attributes['height'] . ' <br/>');

        print_r('Duration: ' . $video_attributes['hours'] . ':' . $video_attributes['mins'] . ':'
                . $video_attributes['secs'] . '.' . $video_attributes['ms'] . '<br/>');

        print_r('Size:  ' . _human_filesize(filesize($vid)));

    } else {
        print_r('File is not a video.');
    }
} else {
    print_r('File does not exist.');
}
?>