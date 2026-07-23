<?php

class Storage
{
    private string $entryDir;

    public function __construct()
    {
        $this->entryDir = __DIR__ . '/../storage/entries';
        if (!is_dir($this->entryDir)) {
            mkdir($this->entryDir, 0777, true);
        }
    }

    public function saveEntry(string $date, string $source, array $parsed, ?string $sessionId = null): string
    {
        $id = $date . '_' . bin2hex(random_bytes(6));
        $entry = [
            'id'             => $id,
            'date'           => $date,
            'source'         => $source,
            'session_id'     => $sessionId ?? '',
            'title'          => $parsed['title'] ?? '',
            'parsed_at'      => date('c'),
            'highlights'     => $this->dedup($parsed['highlights'] ?? []),
            'todos'          => $parsed['todos'] ?? [],
            'code_snippets'  => $parsed['code_blocks'] ?? [],
            'rounds'         => $parsed['rounds'] ?? [],
            'key_decisions'  => $this->extractDecisions($parsed['highlights'] ?? []),
            'stats'          => $parsed['stats'] ?? [],
        ];
        $file = $this->entryDir . "/{$id}.json";
        file_put_contents($file, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $id;
    }

    public function getEntries(string $date): array
    {
        $entries = [];
        $pattern = $this->entryDir . "/{$date}_*.json";
        foreach (glob($pattern) as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data) $entries[] = $data;
        }
        usort($entries, fn($a, $b) => ($a['parsed_at'] ?? '') <=> ($b['parsed_at'] ?? ''));
        return $entries;
    }

    public function getEntry(string $id): ?array
    {
        $file = $this->entryDir . "/{$id}.json";
        if (!file_exists($file)) return null;
        $data = json_decode(file_get_contents($file), true);
        return $data ?: null;
    }

    public function deleteEntry(string $id): bool
    {
        $file = $this->entryDir . "/{$id}.json";
        if (file_exists($file)) {
            unlink($file);
            return true;
        }
        return false;
    }

    public function listDays(): array
    {
        $days = [];
        foreach (glob($this->entryDir . "/*.json") as $file) {
            $name = basename($file);
            $date = substr($name, 0, 10);
            $days[$date] = ($days[$date] ?? 0) + 1;
        }
        krsort($days);
        $result = [];
        foreach ($days as $date => $count) {
            $outputFile = __DIR__ . '/../output/' . $date . '.md';
            $result[] = [
                'date'       => $date,
                'entries'    => $count,
                'generated'  => file_exists($outputFile),
            ];
        }
        return $result;
    }

    private function dedup(array $items): array
    {
        return array_values(array_unique($items));
    }

    private function extractDecisions(array $highlights): array
    {
        $keywords = ['决定', '选择', '方案', '结论', '采用', '放弃'];
        $decisions = [];
        foreach ($highlights as $h) {
            foreach ($keywords as $kw) {
                if (mb_strpos($h, $kw) !== false) {
                    $decisions[] = $h;
                    break;
                }
            }
        }
        return $decisions;
    }
}
