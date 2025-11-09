<?php
if (!defined('ABSPATH')) exit;

class FileSplitter {
    private $path;
    private $name;
    private $size;

    public function __construct($path, $name, $size = 49 * 1024 * 1024) {
        $this->path = $path;
        $this->name = $name;
        $this->size = $size;
    }

    public function split() {
        $handle = fopen($this->path, 'rb');
        $parts = [];
        $part = 1;
        $basename = pathinfo($this->name, PATHINFO_FILENAME);
        $ext = pathinfo($this->name, PATHINFO_EXTENSION);

        while (!feof($handle)) {
            $chunk = fread($handle, $this->size);
            $tmp = tempnam(sys_get_temp_dir(), 'split');
            file_put_contents($tmp, $chunk);
            $parts[] = [
                'path' => $tmp,
                'name' => "{$basename}_part{$part}.{$ext}",
                'size' => strlen($chunk)
            ];
            $part++;
        }

        fclose($handle);
        return $parts;
    }
}
