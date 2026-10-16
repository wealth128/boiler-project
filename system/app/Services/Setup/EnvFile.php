<?php

namespace App\Services\Setup;

use RuntimeException;

/**
 * Reads and writes the .env file, one KEY=value line at a time. Comments
 * and the order of the lines stay as they are. Used by census:install.
 */
class EnvFile
{
    /** @var list<string> */
    private array $lines = [];

    public function __construct(private readonly string $path)
    {
        if (is_file($path)) {
            $this->lines = $this->split((string) file_get_contents($path));
        }
    }

    /** Start from another file's lines (.env.example) but save to this path. */
    public static function fromExample(string $examplePath, string $targetPath): self
    {
        if (! is_file($examplePath)) {
            throw new RuntimeException('The .env.example file is missing.');
        }

        $env = new self($targetPath);
        $env->lines = $env->split((string) file_get_contents($examplePath));

        return $env;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /** The value of a key, without quotes; null when the key is not there. */
    public function get(string $key): ?string
    {
        foreach ($this->lines as $line) {
            if (preg_match('/^'.preg_quote($key, '/').'=(.*)$/', $line, $match)) {
                return $this->unquote($match[1]);
            }
        }

        return null;
    }

    /** True when the key is there with a value that is not empty. */
    public function filled(string $key): bool
    {
        return trim((string) $this->get($key)) !== '';
    }

    public function set(string $key, string $value): void
    {
        $line = $key.'='.$this->quote($value);

        foreach ($this->lines as $i => $existing) {
            if (preg_match('/^'.preg_quote($key, '/').'=/', $existing)) {
                $this->lines[$i] = $line;

                return;
            }
        }

        $this->lines[] = $line;
    }

    /** @return list<string> Every key in the file. */
    public function keys(): array
    {
        $keys = [];

        foreach ($this->lines as $line) {
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=/', $line, $match)) {
                $keys[] = $match[1];
            }
        }

        return $keys;
    }

    public function save(): void
    {
        $text = implode(PHP_EOL, $this->lines).PHP_EOL;

        if (file_put_contents($this->path, $text) === false) {
            throw new RuntimeException('The .env file could not be written.');
        }
    }

    /** @return list<string> */
    private function split(string $text): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text; // the example file starts with a BOM

        return preg_split('/\r\n|\n|\r/', rtrim($text, "\r\n")) ?: [];
    }

    private function quote(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_\-.:\/@,+=]*$/', $value) === 1) {
            return $value;
        }

        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
    }

    private function unquote(string $value): string
    {
        $value = trim($value);

        if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            return str_replace(['\\"', '\\$', '\\\\'], ['"', '$', '\\'], substr($value, 1, -1));
        }

        if (strlen($value) >= 2 && $value[0] === "'" && str_ends_with($value, "'")) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
