<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare(strict_types=1);

// Writes a ZIP archive whose entries are stored, not compressed, like the one the engine makes
// (photos and videos are compressed already). Plain PHP, so the zip extension is not needed.
// No ZIP64, so the whole archive must stay under 4 GiB.
final class Zip
{
    private const LIMIT = 0xFFFFFFFF;

    /**
     * Writes $out from $entries (name in the archive => path on disk), through a temporary file
     * that takes its place only when complete. Returns the size of the archive.
     *
     * @param array<string, string> $entries
     */
    public static function stored(string $out, array $entries): int
    {
        $total = 22;
        foreach ($entries as $name => $path) {
            $total += 2 * (30 + strlen($name)) + (int) filesize($path) + 16;
        }
        if ($total >= self::LIMIT) {
            throw new RuntimeException('the archive would pass 4 GiB');
        }

        $tmp = $out . '.' . getmypid() . '.tmp';
        $fh = fopen($tmp, 'wb');
        if ($fh === false) {
            throw new RuntimeException('cannot write ' . basename($tmp));
        }
        try {
            $central = '';
            $offset = 0;
            foreach ($entries as $name => $path) {
                $name = (string) $name;
                $size = (int) filesize($path);
                $crc = (int) hexdec((string) hash_file('crc32b', $path));
                [$time, $date] = self::dosTime((int) filemtime($path));
                $fields = pack('vvvvvVVVv', 20, 0, 0, $time, $date, $crc, $size, $size, strlen($name));
                fwrite($fh, pack('V', 0x04034b50) . $fields . pack('v', 0) . $name);
                $in = fopen($path, 'rb');
                if ($in === false || stream_copy_to_stream($in, $fh) !== $size) {
                    throw new RuntimeException('cannot read ' . $name);
                }
                fclose($in);
                // made by: Unix, 2.0; external attributes: a regular file, rw-r--r--
                $central .= pack('V', 0x02014b50) . pack('v', 0x0314) . $fields
                    . pack('vvvvVV', 0, 0, 0, 0, 0100644 << 16, $offset) . $name;
                $offset += 30 + strlen($name) + $size;
            }
            fwrite($fh, $central);
            $n = count($entries);
            fwrite($fh, pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($central), $offset, 0));
            if (!fclose($fh)) {
                throw new RuntimeException('cannot finish ' . basename($tmp));
            }
            $fh = null;
            if (!rename($tmp, $out)) {
                throw new RuntimeException('cannot move the archive into place');
            }
        } catch (Throwable $err) {
            if (is_resource($fh)) {
                fclose($fh);
            }
            @unlink($tmp);
            throw $err;
        }
        return (int) filesize($out);
    }

    /** @return array{int, int} the MS-DOS time and date fields of a local timestamp */
    private static function dosTime(int $ts): array
    {
        $t = getdate(max($ts, 315532800));
        return [
            ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2),
            (($t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'],
        ];
    }
}
