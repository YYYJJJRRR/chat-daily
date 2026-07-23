<?php

class DailyGenerator
{
    private string $outputDir;

    public function __construct()
    {
        $this->outputDir = __DIR__ . '/../output';
        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0777, true);
        }
    }

    public function generate(string $date, array $entries): string
    {
        $merged = $this->mergeEntries($entries);
        $lines = $this->buildReport("# {$date} 日报", $date, $merged);
        return $this->write($date, $lines);
    }

    public function generateWeekly(string $startDate, string $endDate, array $entries): string
    {
        $label = "{$startDate} ~ {$endDate}";
        $merged = $this->mergeEntries($entries);
        $merged['days'] = count(array_unique(array_map(fn($e) => $e['date'] ?? '', $entries)));

        $lines = $this->buildReport("# 📅 周报：{$label}", $label, $merged);
        $lines[] = "📆 覆盖 {$merged['days']} 天";
        $lines[] = '';

        $filename = "weekly_{$startDate}_{$endDate}.md";
        return $this->write($filename, $lines);
    }

    public function generateMonthly(string $yearMonth, array $entries): string
    {
        $merged = $this->mergeEntries($entries);
        $merged['days'] = count(array_unique(array_map(fn($e) => $e['date'] ?? '', $entries)));

        $lines = $this->buildReport("# 📊 月报：{$yearMonth}", $yearMonth, $merged);
        $lines[] = "📆 覆盖 {$merged['days']} 天";
        $lines[] = '';

        $filename = "monthly_{$yearMonth}.md";
        return $this->write($filename, $lines);
    }

    private function mergeEntries(array $entries): array
    {
        $highlights = [];
        $codes = [];
        $seenCodes = [];
        $todos = [];
        $decisions = [];
        $rounds = [];
        $totalStats = ['rounds' => 0, 'code_blocks' => 0, 'todos' => 0, 'highlights' => 0];

        foreach ($entries as $e) {
            foreach ($e['highlights'] ?? [] as $h) {
                $highlights[$h] = true;
            }
            foreach ($e['code_snippets'] ?? [] as $c) {
                $key = md5($c['code'] ?? '');
                if (!isset($seenCodes[$key])) {
                    $seenCodes[$key] = true;
                    $codes[] = $c;
                }
            }
            foreach ($e['todos'] ?? [] as $t) {
                $todos[] = $t;
            }
            foreach ($e['key_decisions'] ?? [] as $d) {
                $decisions[$d] = true;
            }
            foreach ($e['rounds'] ?? [] as $r) {
                $rounds[] = $r;
            }
            $s = $e['stats'] ?? [];
            $totalStats['rounds'] += $s['rounds'] ?? 0;
            $totalStats['code_blocks'] += $s['code_blocks'] ?? 0;
            $totalStats['todos'] += $s['todos'] ?? 0;
            $totalStats['highlights'] += $s['highlights'] ?? 0;
        }

        return compact('highlights', 'codes', 'todos', 'decisions', 'rounds', 'totalStats');
    }

    private function buildReport(string $title, string $dateOrRange, array $merged): array
    {
        $lines = [];
        $lines[] = $title;
        $lines[] = '';

        if ($merged['highlights']) {
            $lines[] = '## 📌 要点';
            foreach (array_keys($merged['highlights']) as $h) {
                $lines[] = "- {$h}";
            }
            $lines[] = '';
        }

        if ($merged['codes']) {
            $lines[] = '## 🧩 关键代码';
            foreach ($merged['codes'] as $c) {
                $lang = $c['lang'] ?? '';
                $lines[] = "```{$lang}";
                $lines[] = $c['code'] ?? '';
                $lines[] = '```';
            }
            $lines[] = '';
        }

        if ($merged['decisions']) {
            $lines[] = '## 📝 技术决策';
            foreach (array_keys($merged['decisions']) as $d) {
                $lines[] = "- {$d}";
            }
            $lines[] = '';
        }

        if ($merged['todos']) {
            $lines[] = '## ✅ 待办';
            foreach ($merged['todos'] as $t) {
                $check = ($t['done'] ?? false) ? 'x' : ' ';
                $lines[] = "- [{$check}] {$t['text']}";
            }
            $lines[] = '';
        }

        $s = $merged['totalStats'];
        $lines[] = '## 💬 对话摘要';
        foreach ($merged['rounds'] as $r) {
            $role = ($r['role'] ?? '') === 'user' ? '🙋' : '🤖';
            $content = trim($r['content'] ?? '');
            if ($content) {
                $lines[] = "> **{$role}** {$content}";
            }
        }
        $lines[] = '';

        $lines[] = '---';
        $lines[] = "_{$dateOrRange} 共 {$s['rounds']} 轮对话、{$s['code_blocks']} 个代码块、{$s['todos']} 个待办、{$s['highlights']} 个关键句_";

        return $lines;
    }

    private function write(string $filename, array $lines): string
    {
        $content = implode("\n", $lines);
        $outputFile = $this->outputDir . "/{$filename}";
        file_put_contents($outputFile, $content);
        return $outputFile;
    }

    public function getDaily(string $date): ?string
    {
        $file = $this->outputDir . "/{$date}.md";
        return file_exists($file) ? file_get_contents($file) : null;
    }
}
