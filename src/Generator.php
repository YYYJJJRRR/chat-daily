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

        $lines = [];
        $lines[] = "# {$date} 日报";
        $lines[] = '';

        // Highlights
        if ($highlights) {
            $lines[] = '## 📌 今日要点';
            foreach (array_keys($highlights) as $h) {
                $lines[] = "- {$h}";
            }
            $lines[] = '';
        }

        // Code
        if ($codes) {
            $lines[] = '## 🧩 关键代码';
            foreach ($codes as $c) {
                $lang = $c['lang'] ?? '';
                $lines[] = "```{$lang}";
                $lines[] = $c['code'] ?? '';
                $lines[] = '```';
            }
            $lines[] = '';
        }

        // Decisions
        if ($decisions) {
            $lines[] = '## 📝 技术决策';
            foreach (array_keys($decisions) as $d) {
                $lines[] = "- {$d}";
            }
            $lines[] = '';
        }

        // Todos
        if ($todos) {
            $lines[] = '## ✅ 待办';
            foreach ($todos as $t) {
                $check = ($t['done'] ?? false) ? 'x' : ' ';
                $lines[] = "- [{$check}] {$t['text']}";
            }
            $lines[] = '';
        }

        // Conversation summary
        $lines[] = "## 💬 对话摘要（{$totalStats['rounds']} 轮）";
        foreach ($rounds as $r) {
            $role = ($r['role'] ?? '') === 'user' ? '🙋' : '🤖';
            $content = trim($r['content'] ?? '');
            if ($content) {
                $lines[] = "> **{$role}** {$content}";
            }
        }
        $lines[] = '';

        // Stats footer
        $lines[] = '---';
        $lines[] = "_{$date} 共 {$totalStats['rounds']} 轮对话、{$totalStats['code_blocks']} 个代码块、{$totalStats['todos']} 个待办、{$totalStats['highlights']} 个关键句_";

        $content = implode("\n", $lines);
        $outputFile = $this->outputDir . "/{$date}.md";
        file_put_contents($outputFile, $content);
        return $outputFile;
    }

    public function getDaily(string $date): ?string
    {
        $file = $this->outputDir . "/{$date}.md";
        return file_exists($file) ? file_get_contents($file) : null;
    }
}
