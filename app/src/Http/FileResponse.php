<?php

declare(strict_types=1);

namespace Belprise\Http;

/**
 * Streams a file from disk, with HTTP Range support (206 / 416).
 * With X-Sendfile enabled, Apache (mod_xsendfile) sends the file and
 * handles Range itself.
 */
final class FileResponse extends Response
{
    private const BLOCK = 1024 * 1024;

    private int $start = 0;
    private int $length = 0;
    private bool $sendfile = false;

    public function __construct(
        private readonly string $file,
        string $contentType,
        ?string $rangeHeader,
        bool $xsendfile = false,
        /**
         * Playback: a range request ("bytes=N-"…) is answered with this many bytes
         * at most (a 206 is only ever sent to a Range request). The player asks for the next part itself; the
         * server never reads gigabytes for a seek (shared hosts cap disk reads).
         */
        ?int $maxChunk = null,
    ) {
        parent::__construct(200, '', $contentType);
        clearstatcache(true, $file); // the size now, not the one PHP saw earlier in this process
        $size = (int) filesize($file);
        $this->setHeader('Accept-Ranges', 'bytes');

        if ($xsendfile) {
            $this->sendfile = true;
            // Canonical path ("app/../data" would not match XSendFilePath).
            $this->setHeader('X-Sendfile', realpath($file) ?: $file);

            return;
        }

        $range = $rangeHeader === null ? null : self::parseRange($rangeHeader, $size);
        if ($maxChunk !== null && is_array($range) && $range[1] - $range[0] + 1 > $maxChunk) {
            $range = [$range[0], $range[0] + $maxChunk - 1];
        }
        if ($range === false) {
            $this->status = 416;
            $this->setHeader('Content-Range', 'bytes */' . $size);
            $this->setHeader('Content-Length', '0');

            return;
        }
        if ($range === null) {
            $this->length = $size;
        } else {
            [$this->start, $end] = $range;
            $this->length = $end - $this->start + 1;
            $this->status = 206;
            $this->setHeader('Content-Range', 'bytes ' . $this->start . '-' . $end . '/' . $size);
        }
        $this->setHeader('Content-Length', (string) $this->length);
    }

    /**
     * Single range "bytes=a-b", "bytes=a-" or "bytes=-n". Null: no usable
     * range (send the whole file); false: unsatisfiable (416).
     *
     * @return array{int, int}|null|false
     */
    public static function parseRange(string $header, int $size): array|null|false
    {
        if (preg_match('/^bytes=(\d*)-(\d*)$/Di', trim($header), $m) !== 1 || ($m[1] === '' && $m[2] === '')) {
            return null; // malformed or multiple ranges: ignore, as RFC 9110 allows
        }
        if ($m[1] === '') {
            $suffix = (int) $m[2];
            if ($suffix === 0 || $size === 0) {
                return false;
            }

            return [max(0, $size - $suffix), $size - 1];
        }
        $start = (int) $m[1];
        if ($m[2] !== '' && (int) $m[2] < $start) {
            return null; // invalid range-spec ("5-2"): ignored, the whole file is sent
        }
        $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
        if ($start >= $size) {
            return false;
        }

        return [$start, $end];
    }

    /** Bytes that send() would output (tests). */
    public function contents(): string
    {
        if ($this->sendfile || $this->status === 416) {
            return '';
        }

        return (string) file_get_contents($this->file, false, null, $this->start, $this->length);
    }

    public function send(bool $withBody = true): void
    {
        parent::send(false);
        if (!$withBody || $this->sendfile || $this->status === 416) {
            return;
        }
        set_time_limit(0);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $handle = fopen($this->file, 'rb');
        if ($handle === false) {
            return;
        }
        fseek($handle, $this->start);
        $remaining = $this->length;
        while ($remaining > 0 && !feof($handle) && connection_status() === CONNECTION_NORMAL) {
            $block = fread($handle, min(self::BLOCK, $remaining));
            if ($block === false || $block === '') {
                break;
            }
            echo $block;
            flush();
            $remaining -= strlen($block);
        }
        fclose($handle);
    }
}
