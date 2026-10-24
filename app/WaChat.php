<?php
declare(strict_types=1);

/**
 * WhatsApp chat export reader (HR-04 v3, docs/任务卡-拜访签到.md §一). Pure
 * functions: text in, messages and visit candidates out; no database.
 *
 * Formats (checked against real exports, fixtures in samples_test/wa_chat_sample/):
 *   iPhone / web exporter  [M/D/YY, h:mm:ss AM/PM] Sender: text
 *                          media <attached: 00000012-PHOTO-….jpg> | <image omitted>; <album message>
 *   Android                DD/MM/YY HH.MM - Sender: text   (HH:MM in English)
 *                          media IMG-….jpg (file attached) | <Media tidak disertakan> | <Media omitted>
 * Day/month order is read from the file itself (a first number above 12 means
 * D/M, a second above 12 means M/D); without evidence iPhone = M/D, Android = D/M.
 *
 * A visit = a photo message with a caption (the caption is on the same line,
 * continuation lines have no prefix). Captionless photos right after it from
 * the same sender in the same minute belong to it. A captionless photo
 * followed within two minutes by a text-only message from the same sender is
 * one visit too (photo and text sent separately). Everything else is ignored
 * and counted.
 */
final class WaChat
{
    private const IOS = '/^\[(\d{1,2})[\/.](\d{1,2})[\/.](\d{2,4}),?\s+(\d{1,2})[:.](\d{2})(?:[:.](\d{2}))?\s*([AaPp]\.?[Mm]\.?)?\]\s+(.+?):\s(.*)$/u';
    private const ANDROID = '/^(\d{1,2})\/(\d{1,2})\/(\d{2,4}),?\s+(\d{1,2})[:.](\d{2})(?:\s*([AaPp]\.?[Mm]\.?))?\s+-\s+(.+?):\s(.*)$/u';
    private const ANDROID_SYSTEM = '/^\d{1,2}\/\d{1,2}\/\d{2,4},?\s+\d{1,2}[:.]\d{2}(?:\s*[AaPp]\.?[Mm]\.?)?\s+-\s+/u';
    private const DELETED = ['this message was deleted', 'you deleted this message', 'pesan ini telah dihapus', 'anda menghapus pesan ini'];

    /** Strip the invisible marks WhatsApp sprinkles in (U+200E/F, U+202A–E, BOM) and normalise line ends. */
    public static function clean(string $txt): string
    {
        $txt = str_replace(["\r\n", "\r"], "\n", $txt);
        return (string) preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{FEFF}]/u', '', $txt);
    }

    /**
     * Messages in file order.
     * @return list<array{ts:string,sender:string,text:string,media:list<?string>,deleted:bool,album:bool,format:string}>
     *   media: file names in the export (null = media left out of the export)
     */
    public static function messages(string $txt): array
    {
        $lines = explode("\n", self::clean($txt));
        $raw = [];
        foreach ($lines as $line) {
            if (preg_match(self::IOS, $line, $m)) {
                $raw[] = ['fmt' => 'ios', 'a' => (int) $m[1], 'b' => (int) $m[2], 'y' => (int) $m[3], 'h' => (int) $m[4], 'i' => (int) $m[5], 's' => (int) ($m[6] ?? 0),
                          'ampm' => strtoupper(str_replace('.', '', (string) ($m[7] ?? ''))), 'sender' => trim($m[8]), 'text' => $m[9]];
            } elseif (preg_match(self::ANDROID, $line, $m)) {
                $raw[] = ['fmt' => 'android', 'a' => (int) $m[1], 'b' => (int) $m[2], 'y' => (int) $m[3], 'h' => (int) $m[4], 'i' => (int) $m[5], 's' => 0,
                          'ampm' => strtoupper(str_replace('.', '', (string) ($m[6] ?? ''))), 'sender' => trim($m[7]), 'text' => $m[8]];
            } elseif (preg_match(self::ANDROID_SYSTEM, $line) || (str_starts_with($line, '[') && preg_match('/^\[\d{1,2}[\/.]\d{1,2}[\/.]\d{2,4}/', $line))) {
                $raw[] = null;   // a system line ("… joined", "end-to-end encrypted"): ends the previous message, no sender
            } elseif ($raw !== [] && end($raw) !== null) {
                $raw[count($raw) - 1]['text'] .= "\n" . $line;
            }
        }
        $raw = array_values(array_filter($raw));
        $dayFirst = self::day_first($raw);
        $out = [];
        foreach ($raw as $r) {
            [$day, $mon] = $dayFirst[$r['fmt']] ? [$r['a'], $r['b']] : [$r['b'], $r['a']];
            $year = $r['y'] < 100 ? 2000 + $r['y'] : $r['y'];
            $h = $r['h'];
            if ($r['ampm'] === 'PM' && $h < 12) {
                $h += 12;
            } elseif ($r['ampm'] === 'AM' && $h === 12) {
                $h = 0;
            }
            if (!checkdate($mon, $day, $year)) {
                continue;
            }
            [$text, $media, $album] = self::split_media(rtrim($r['text']));
            $out[] = [
                'ts' => sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $mon, $day, $h, $r['i'], $r['s']),
                'sender' => $r['sender'], 'text' => $text, 'media' => $media, 'album' => $album,
                'deleted' => in_array(mb_strtolower(trim($text)), self::DELETED, true), 'format' => $r['fmt'],
            ];
        }
        return $out;
    }

    /** @return array{ios:bool,android:bool} true = the first number is the day */
    private static function day_first(array $raw): array
    {
        $res = ['ios' => false, 'android' => true];
        foreach (['ios', 'android'] as $fmt) {
            foreach ($raw as $r) {
                if ($r['fmt'] !== $fmt) {
                    continue;
                }
                if ($r['a'] > 12) {
                    $res[$fmt] = true;
                    break;
                }
                if ($r['b'] > 12) {
                    $res[$fmt] = false;
                    break;
                }
            }
        }
        return $res;
    }

    /**
     * Pull the media markers out of a message.
     * @return array{0:string,1:list<?string>,2:bool} caption, media (file name or null), album marker
     */
    public static function split_media(string $text): array
    {
        $media = [];
        $album = false;
        $text = (string) preg_replace_callback('/<attached:\s*([^>]+)>/i', function ($m) use (&$media) {
            $media[] = trim($m[1]);
            return '';
        }, $text);
        $text = (string) preg_replace_callback('/^\s*(\S+\.(?:jpe?g|png|webp|heic|mp4|opus))\s+\((?:file attached|file terlampir|lampiran)\)/imu', function ($m) use (&$media) {
            $media[] = trim($m[1]);
            return '';
        }, $text);
        $text = (string) preg_replace_callback('/<(?:image|video|gif|sticker) omitted>|<media omitted>|<media tidak disertakan>|<gambar tidak disertakan>/i', function () use (&$media) {
            $media[] = null;
            return '';
        }, $text);
        if (preg_match('/^\s*<album message>\s*$/i', $text)) {
            $album = true;
            $text = '';
        }
        return [trim($text), $media, $album];
    }

    /** Same person whatever the export wrote: digits for a phone number, folded case for a name. */
    public static function sender_key(string $sender): string
    {
        $s = trim($sender);
        $digits = preg_replace('/\D+/', '', $s) ?? '';
        if (preg_match('/^\+?[\d\s\-().]{8,}$/', $s) && strlen($digits) >= 8) {
            return str_starts_with($digits, '0') ? '62' . substr($digits, 1) : $digits;
        }
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', $s));
    }

    /** Dedup key: sender + time + caption (media markers excluded, so a text-only and a with-media export agree). */
    public static function hash(string $sender, string $ts, string $caption): string
    {
        return sha1(self::sender_key($sender) . '|' . $ts . '|' . preg_replace('/\s+/u', ' ', trim($caption)));
    }

    /**
     * Visit candidates.
     * @return array{visits:list<array{ts:string,sender:string,caption:string,photos:list<?string>,hash:string,merged:bool}>,messages:int,ignored:int}
     */
    public static function visits(array $messages): array
    {
        $visits = [];
        $used = 0;
        $n = count($messages);
        for ($i = 0; $i < $n; $i++) {
            $m = $messages[$i];
            if ($m['deleted'] || $m['album'] || $m['media'] === []) {
                continue;
            }
            $caption = $m['text'];
            $merged = false;
            $j = $i + 1;
            if ($caption === '') {
                // Photo first, text as its own message right after (same sender, ≤ 2 minutes).
                $nx = $messages[$j] ?? null;
                if ($nx && !$nx['deleted'] && $nx['media'] === [] && $nx['text'] !== '' && $nx['sender'] === $m['sender']
                    && abs(strtotime($nx['ts']) - strtotime($m['ts'])) <= 120) {
                    $caption = $nx['text'];
                    $merged = true;
                    $j++;
                } else {
                    continue;   // an extra photo not following any visit: ignored
                }
            }
            $photos = $m['media'];
            $used += $merged ? 2 : 1;
            // Captionless photos right after, same sender, same minute: the same visit.
            while ($j < $n && $messages[$j]['sender'] === $m['sender'] && $messages[$j]['media'] !== [] && $messages[$j]['text'] === ''
                   && !$messages[$j]['deleted'] && substr($messages[$j]['ts'], 0, 16) === substr($m['ts'], 0, 16)) {
                array_push($photos, ...$messages[$j]['media']);
                $used++;
                $j++;
            }
            $visits[] = ['ts' => $m['ts'], 'sender' => $m['sender'], 'caption' => $caption, 'photos' => $photos,
                         'hash' => self::hash($m['sender'], $m['ts'], $caption), 'merged' => $merged];
            $i = $j - 1;
        }
        return ['visits' => $visits, 'messages' => $n, 'ignored' => $n - $used];
    }

    /**
     * The chat text and the media files of an export (zip or a bare .txt).
     * Photos are unpacked to a temp dir (a day's zip can be tens of MB; memory
     * stays flat); call cleanup() when done. The web exporter's chat.md is ignored.
     * @return array{txt:?string,files:array<string,array{path:string}>,tmp:?string} basename => temp file
     */
    public static function read_upload(string $path, string $name): array
    {
        if (!preg_match('/\.zip$/i', $name)) {
            return ['txt' => (string) file_get_contents($path), 'files' => [], 'tmp' => null];
        }
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('zip extension missing');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('not a zip file');
        }
        $txt = null;
        $files = [];
        $tmp = sys_get_temp_dir() . '/wa-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0700, true);
        for ($k = 0; $k < $zip->numFiles; $k++) {
            $entry = (string) $zip->getNameIndex($k);
            $base = basename($entry);
            if ($base === '' || str_ends_with($entry, '/') || str_starts_with($base, '.') || str_contains($entry, '__MACOSX')) {
                continue;
            }
            if (preg_match('/\.txt$/i', $base)) {
                if ($txt === null || preg_match('/chat/i', $base)) {
                    $txt = (string) $zip->getFromIndex($k);
                }
            } elseif (preg_match('/\.(jpe?g|png|webp)$/i', $base)) {
                $in = $zip->getStream($entry);
                if ($in !== false) {
                    $dest = $tmp . '/' . $k . '.img';
                    $out = fopen($dest, 'wb');
                    stream_copy_to_stream($in, $out);
                    fclose($out);
                    fclose($in);
                    $files[$base] = ['path' => $dest];
                }
            }
        }
        $zip->close();
        return ['txt' => $txt, 'files' => $files, 'tmp' => $tmp];
    }

    public static function cleanup(?string $tmp): void
    {
        if ($tmp !== null && is_dir($tmp) && str_starts_with(basename($tmp), 'wa-')) {
            foreach (glob($tmp . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tmp);
        }
    }
}
