<?php

namespace VersionMatrix;

class State
{
    private string $file;

    public function __construct(string $file)
    {
        $this->file = $file;
    }

    public function load(): array
    {
        if (!file_exists($this->file)) {
            return [];
        }
        $raw = file_get_contents($this->file);
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public function save(array $instances): void
    {
        file_put_contents($this->file, json_encode(array_values($instances), JSON_PRETTY_PRINT));
    }
}
