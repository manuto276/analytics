<?php

declare(strict_types=1);

namespace AnalyticsDeploy\Tests\Support;

/** Writes arbitrary (including malicious) ustar archives. */
final class TarWriter
{
    private string $data = '';

    public function file(string $name, string $content, int $mode = 0644): self
    {
        $this->data .= self::header($name, $mode, \strlen($content), '0');
        $this->data .= $content . str_repeat("\0", (512 - \strlen($content) % 512) % 512);

        return $this;
    }

    public function dir(string $name, int $mode = 0755): self
    {
        $this->data .= self::header(rtrim($name, '/') . '/', $mode, 0, '5');

        return $this;
    }

    public function symlink(string $name, string $target): self
    {
        $this->data .= self::header($name, 0777, 0, '2', $target);

        return $this;
    }

    public function gz(): string
    {
        return gzencode($this->data . str_repeat("\0", 1024), 6);
    }

    private static function header(string $name, int $mode, int $size, string $type, string $link = ''): string
    {
        $prefix = '';
        if (\strlen($name) > 100) {
            $cut = strrpos(substr($name, 0, 156), '/');
            if ($cut === false) {
                throw new \InvalidArgumentException('name too long: ' . $name);
            }
            $prefix = substr($name, 0, $cut);
            $name = substr($name, $cut + 1);
        }
        $h = str_pad($name, 100, "\0")
            . sprintf('%07o', $mode) . "\0"
            . sprintf('%07o', 0) . "\0"
            . sprintf('%07o', 0) . "\0"
            . sprintf('%011o', $size) . "\0"
            . sprintf('%011o', 1767225600) . "\0"
            . '        '
            . $type
            . str_pad($link, 100, "\0")
            . "ustar\0" . '00'
            . str_pad('root', 32, "\0")
            . str_pad('root', 32, "\0")
            . str_repeat("\0", 16)
            . str_pad($prefix, 155, "\0")
            . str_repeat("\0", 12);
        $sum = 0;
        for ($i = 0; $i < 512; ++$i) {
            $sum += \ord($h[$i]);
        }

        return substr_replace($h, sprintf('%06o', $sum) . "\0 ", 148, 8);
    }
}
