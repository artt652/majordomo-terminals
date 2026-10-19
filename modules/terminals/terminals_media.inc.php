<?php
/*
 * Длительность звуковых и видеофайлов (секунды, с дробью; 0 — не узнали).
 *
 * По порядку: заголовок файла без внешних программ — MP4/MOV/3GP/M4A (блок mvhd), WAV (fmt/data),
 * MP3 (Xing/Info/VBRI или постоянный битрейт), OGG Vorbis/Opus (позиция последней страницы); затем
 * ffprobe, если установлен; затем функция ядра getMediaDurationSeconds() (ffmpeg).
 * Записи из браузера (webm) часто не содержат длительности — её присылает страница «Сообщения».
 */

function terminalsMediaDuration($file)
{
    $file = (string)$file;
    if ($file == '' || !is_file($file) || !is_readable($file)) {
        return 0;
    }
    $fh = @fopen($file, 'rb');
    if (!$fh) {
        return 0;
    }
    $head = (string)fread($fh, 12);
    $size = (int)filesize($file);
    $seconds = 0;
    if (strlen($head) >= 8 && in_array(substr($head, 4, 4), array('ftyp', 'moov', 'mdat', 'free', 'wide', 'skip'), true)) {
        $seconds = terminalsMediaMp4($fh, $size);
    } elseif (substr($head, 0, 4) == 'RIFF' && substr($head, 8, 4) == 'WAVE') {
        $seconds = terminalsMediaWav($fh, $size);
    } elseif (substr($head, 0, 4) == 'OggS') {
        $seconds = terminalsMediaOgg($fh, $size);
    } elseif (substr($head, 0, 3) == 'ID3' || (strlen($head) >= 2 && ord($head[0]) == 0xFF && (ord($head[1]) & 0xE0) == 0xE0)) {
        $seconds = terminalsMediaMp3($fh, $size);
    }
    fclose($fh);
    if ($seconds > 0 && $seconds < 86400) {
        return round($seconds, 2);
    }
    return terminalsMediaDurationTool($file);
}

// ffprobe (точно, с дробью), иначе ffmpeg через функцию ядра
function terminalsMediaDurationTool($file)
{
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (!function_exists('shell_exec') || in_array('shell_exec', $disabled, true)) {
        return 0;
    }
    if (defined('PATH_TO_FFMPEG')) {
        $ffprobe = preg_replace('/ffmpeg(\.exe)?$/i', 'ffprobe$1', PATH_TO_FFMPEG);
    } elseif (function_exists('IsWindowsOS') && IsWindowsOS()) {
        $ffprobe = SERVER_ROOT . '/apps/ffmpeg/ffprobe.exe';
    } else {
        $ffprobe = 'ffprobe';
    }
    if (strpos($ffprobe, '/') === false || is_file($ffprobe)) {
        $out = trim((string)@shell_exec(escapeshellarg($ffprobe) . ' -v error -show_entries format=duration -of csv=p=0 '
            . escapeshellarg($file) . ' 2>&1'));
        if (preg_match('/^\d+(\.\d+)?$/', $out) && (float)$out > 0) {
            return round((float)$out, 2);
        }
    }
    if (function_exists('getMediaDurationSeconds')) {
        $seconds = getMediaDurationSeconds(escapeshellarg($file));
        return ($seconds > 0) ? (float)$seconds : 0;
    }
    return 0;
}

// Беззнаковое 64-битное (старшие и младшие 32 бита) как число с плавающей точкой
function terminalsMediaU64($hi, $lo)
{
    return (float)$hi * 4294967296.0 + (float)$lo;
}

// MP4/MOV/3GP/M4A: moov → mvhd (масштаб времени и длительность)
function terminalsMediaMp4($fh, $size)
{
    $pos = 0;
    $end = $size;
    $guard = 0;
    while ($pos + 8 <= $end && $guard++ < 1000) {
        fseek($fh, $pos);
        $hdr = fread($fh, 16);
        if (strlen($hdr) < 8) {
            return 0;
        }
        $box = unpack('Nsize', substr($hdr, 0, 4));
        $len = (float)$box['size'];
        $type = substr($hdr, 4, 4);
        $skip = 8;
        if ($len == 1 && strlen($hdr) >= 16) {
            $big = unpack('Nhi/Nlo', substr($hdr, 8, 8));
            $len = terminalsMediaU64($big['hi'], $big['lo']);
            $skip = 16;
        } elseif ($len == 0) {
            $len = $end - $pos;
        }
        if ($len < $skip) {
            return 0;
        }
        if ($type == 'moov') {
            // внутрь moov
            $end = min($end, $pos + $len);
            $pos += $skip;
            continue;
        }
        if ($type == 'mvhd') {
            fseek($fh, $pos + $skip);
            $data = fread($fh, 32);
            if (strlen($data) < 20) {
                return 0;
            }
            if (ord($data[0]) == 1) {
                $v = unpack('Nscale/Nhi/Nlo', substr($data, 20, 12));
                $duration = terminalsMediaU64($v['hi'], $v['lo']);
            } else {
                $v = unpack('Nscale/Nduration', substr($data, 12, 8));
                $duration = (float)$v['duration'];
            }
            return ($v['scale'] > 0) ? $duration / $v['scale'] : 0;
        }
        $pos += $len;
    }
    return 0;
}

// WAV: байт в секунду из fmt, размер блока data
function terminalsMediaWav($fh, $size)
{
    $pos = 12;
    $rate = 0;
    $guard = 0;
    while ($pos + 8 <= $size && $guard++ < 100) {
        fseek($fh, $pos);
        $hdr = fread($fh, 8);
        if (strlen($hdr) < 8) {
            break;
        }
        $id = substr($hdr, 0, 4);
        $len = unpack('Vlen', substr($hdr, 4, 4));
        $len = (float)$len['len'];
        if ($id == 'fmt ') {
            $fmt = fread($fh, 12);
            if (strlen($fmt) >= 12) {
                $v = unpack('vformat/vchannels/Vsamplerate/Vbyterate', $fmt);
                $rate = (float)$v['byterate'];
            }
        } elseif ($id == 'data') {
            $data = min($len, $size - $pos - 8);
            return ($rate > 0) ? $data / $rate : 0;
        }
        $pos += 8 + $len + ($len % 2);
    }
    return 0;
}

// OGG Vorbis/Opus: частота из заголовка, позиция (granule) последней страницы
function terminalsMediaOgg($fh, $size)
{
    fseek($fh, 0);
    $start = (string)fread($fh, 4096);
    $rate = 0;
    $preskip = 0;
    if (($p = strpos($start, "\x01vorbis")) !== false && strlen($start) >= $p + 16) {
        $v = unpack('Vrate', substr($start, $p + 12, 4));
        $rate = (float)$v['rate'];
    } elseif (($p = strpos($start, 'OpusHead')) !== false && strlen($start) >= $p + 12) {
        $v = unpack('vpreskip', substr($start, $p + 10, 2));
        $preskip = (float)$v['preskip'];
        $rate = 48000.0; // позиция Opus всегда в отсчётах 48 кГц
    }
    if ($rate <= 0) {
        return 0;
    }
    $tail_len = (int)min($size, 65536);
    fseek($fh, $size - $tail_len);
    $tail = (string)fread($fh, $tail_len);
    $p = strrpos($tail, 'OggS');
    if ($p === false || strlen($tail) < $p + 14) {
        return 0;
    }
    $g = unpack('Vlo/Vhi', substr($tail, $p + 6, 8));
    if ($g['hi'] == 0xFFFFFFFF) {
        return 0; // позиция не задана
    }
    $granule = terminalsMediaU64($g['hi'], $g['lo']) - $preskip;
    return ($granule > 0) ? $granule / $rate : 0;
}

// MP3 (слой III): Xing/Info или VBRI — число кадров; иначе постоянный битрейт по размеру файла
function terminalsMediaMp3($fh, $size)
{
    fseek($fh, 0);
    $start = 0;
    $id3 = fread($fh, 10);
    if (substr($id3, 0, 3) == 'ID3' && strlen($id3) == 10) {
        // размер тега — 4 байта по 7 бит
        $start = 10 + ((ord($id3[6]) & 0x7F) << 21 | (ord($id3[7]) & 0x7F) << 14 | (ord($id3[8]) & 0x7F) << 7 | (ord($id3[9]) & 0x7F));
        if (ord($id3[5]) & 0x10) {
            $start += 10; // нижний колонтитул
        }
    }
    fseek($fh, $start);
    $buf = (string)fread($fh, 65536);
    $len = strlen($buf);
    for ($i = 0; $i + 4 <= $len; $i++) {
        if (ord($buf[$i]) != 0xFF || (ord($buf[$i + 1]) & 0xE0) != 0xE0) {
            continue;
        }
        $b1 = ord($buf[$i + 1]);
        $b2 = ord($buf[$i + 2]);
        $b3 = ord($buf[$i + 3]);
        $ver = ($b1 >> 3) & 3;   // 3 — MPEG1, 2 — MPEG2, 0 — MPEG2.5
        $layer = ($b1 >> 1) & 3; // 1 — слой III
        $bi = ($b2 >> 4) & 15;
        $si = ($b2 >> 2) & 3;
        if ($ver == 1 || $layer != 1 || $bi == 0 || $bi == 15 || $si == 3) {
            continue;
        }
        $mpeg1 = ($ver == 3);
        $bitrates = $mpeg1 ? array(0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320)
            : array(0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160);
        $rates = array(3 => array(44100, 48000, 32000), 2 => array(22050, 24000, 16000), 0 => array(11025, 12000, 8000));
        $bitrate = $bitrates[$bi] * 1000;
        $rate = $rates[$ver][$si];
        $samples = $mpeg1 ? 1152 : 576;
        $mono = ((($b3 >> 6) & 3) == 3);
        // Xing/Info — сразу после побочной информации кадра
        $side = $mpeg1 ? ($mono ? 17 : 32) : ($mono ? 9 : 17);
        $x = $i + 4 + $side;
        $tag = substr($buf, $x, 4);
        if (($tag == 'Xing' || $tag == 'Info') && strlen($buf) >= $x + 12) {
            $flags = unpack('Nflags', substr($buf, $x + 4, 4));
            if ($flags['flags'] & 1) {
                $frames = unpack('Nframes', substr($buf, $x + 8, 4));
                if ($frames['frames'] > 0) {
                    return $frames['frames'] * $samples / $rate;
                }
            }
        }
        if (substr($buf, $i + 36, 4) == 'VBRI' && strlen($buf) >= $i + 54) {
            $frames = unpack('Nframes', substr($buf, $i + 50, 4));
            if ($frames['frames'] > 0) {
                return $frames['frames'] * $samples / $rate;
            }
        }
        // постоянный битрейт: размер звука (без ID3v1 в конце) / байт в секунду
        $audio = $size - $start - $i;
        fseek($fh, max(0, $size - 128));
        if (fread($fh, 3) == 'TAG') {
            $audio -= 128;
        }
        return ($audio > 0) ? $audio * 8 / $bitrate : 0;
    }
    return 0;
}
