<?php

class Parser
{
    public function parse(string $text): array
    {
        $lines = explode("\n", $text);
        $lines = array_map('trim', $lines);

        $rounds = [];
        $currentRole = null;
        $currentContent = [];

        $codeBlocks = [];
        $todos = [];
        $inCodeBlock = false;
        $codeBlockLang = '';
        $codeBlockContent = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, '```')) {
                if (!$inCodeBlock) {
                    $inCodeBlock = true;
                    $codeBlockLang = trim(substr($line, 3));
                    $codeBlockContent = [];
                } else {
                    $inCodeBlock = false;
                    $code = implode("\n", $codeBlockContent);
                    if ($code !== '') {
                        $codeBlocks[] = [
                            'lang' => $codeBlockLang ?: '',
                            'code' => $code,
                        ];
                    }
                    $currentContent[] = $line;
                }
                continue;
            }

            if ($inCodeBlock) {
                $codeBlockContent[] = $line;
                $currentContent[] = $line;
                continue;
            }

            $role = $this->detectRole($line);
            if ($role !== null) {
                if ($currentRole !== null && count($currentContent) > 0) {
                    $rounds[] = [
                        'role'    => $currentRole,
                        'content' => $this->cleanContent($currentContent),
                    ];
                }
                $currentRole = $role;
                $currentContent = [$line];
                continue;
            }

            if (preg_match('/^-\s*\[([ x])\]\s*(.+)$/i', $line, $m)) {
                $todos[] = [
                    'done'  => trim($m[1]) === 'x',
                    'text'  => trim($m[2]),
                ];
            }

            $currentContent[] = $line;
        }

        if ($currentRole !== null && count($currentContent) > 0) {
            $rounds[] = [
                'role'    => $currentRole,
                'content' => $this->cleanContent($currentContent),
            ];
        }

        // Phase 3: Enhanced compression
        $rounds = $this->filterPoliteness($rounds);
        $rounds = $this->removeToolBoilerplate($rounds);
        $rounds = $this->mergeSimilarAdjacent($rounds);

        $highlights = $this->extractHighlights($text);

        return [
            'rounds'      => $rounds,
            'code_blocks' => $codeBlocks,
            'todos'       => $todos,
            'highlights'  => $highlights,
            'stats'       => [
                'rounds'      => count($rounds),
                'code_blocks' => count($codeBlocks),
                'todos'       => count($todos),
                'highlights'  => count($highlights),
            ],
        ];
    }

    private function detectRole(string $line): ?string
    {
        if (preg_match('/^\*\*(你|我|user|you|human)\s*[:：]\*\*/i', $line)) {
            return 'user';
        }
        if (preg_match('/^\*\*(ai|assistant|chatgpt|gpt|model|bot)\s*[:：]\*\*/i', $line)) {
            return 'assistant';
        }
        return null;
    }

    private function cleanContent(array $lines): string
    {
        $result = preg_replace('/^\*\*(你|我|user|you|human|ai|assistant|chatgpt|gpt|model)\s*[:：]\*\*(\s*)/i', '', $lines[0], 1);
        $lines[0] = $result;

        $lines = array_filter($lines, function ($line) {
            $trimmed = trim($line);
            if ($trimmed === '') return false;
            $stripped = preg_replace('/[\s\p{P}\p{S}]/u', '', $trimmed);
            return $stripped !== '';
        });

        return implode("\n", array_values($lines));
    }

    // ── Phase 3: Enhanced redundancy compression ──

    private function filterPoliteness(array $rounds): array
    {
        $patterns = [
            '/^(谢谢|好的|明白|没问题|对的|是的|不客气|不用谢|客气了)/iu',
            '/^(当然|可以的|这个没问题|可以。)/u',
            '/^(你好|hello|hi)[\s\p{P}]/iu',
        ];

        foreach ($rounds as &$round) {
            $lines = explode("\n", $round['content']);
            $lines = array_filter($lines, function ($line) use ($patterns) {
                $trimmed = trim($line);
                if ($trimmed === '') return false;
                // Keep lines with substantial content after the politeness
                foreach ($patterns as $p) {
                    if (preg_match($p, $trimmed)) {
                        $rest = preg_replace($p, '', $trimmed);
                        if (mb_strlen(trim($rest)) < 5) return false;
                    }
                }
                return true;
            });
            $round['content'] = implode("\n", array_values($lines));
        }

        return array_filter($rounds, fn($r) => trim($r['content']) !== '');
    }

    private function removeToolBoilerplate(array $rounds): array
    {
        $patterns = [
            '/^\[调用工具:.*\]$/iu',
            '/^\[工具返回:.*\]$/iu',
            '/^Looking at the/iu',
            '/^Let me/iu',
            '/^I\'ll help/iu',
            '/^根据项目/iu',
        ];

        foreach ($rounds as &$round) {
            $lines = explode("\n", $round['content']);
            $lines = array_filter($lines, function ($line) use ($patterns) {
                $trimmed = trim($line);
                foreach ($patterns as $p) {
                    if (preg_match($p, $trimmed)) return false;
                }
                return true;
            });
            $round['content'] = implode("\n", array_values($lines));
        }

        return array_filter($rounds, fn($r) => trim($r['content']) !== '');
    }

    private function mergeSimilarAdjacent(array $rounds): array
    {
        $merged = [];
        $prev = null;

        foreach ($rounds as $r) {
            if ($prev === null) {
                $prev = $r;
                continue;
            }

            if ($prev['role'] === $r['role'] && $this->textSimilarity($prev['content'], $r['content']) > 70) {
                $prev['content'] = $prev['content'] . "\n" . $r['content'];
            } else {
                $merged[] = $prev;
                $prev = $r;
            }
        }
        if ($prev !== null) {
            $merged[] = $prev;
        }

        return $merged;
    }

    private function textSimilarity(string $a, string $b): float
    {
        $a = mb_substr($a, 0, 200);
        $b = mb_substr($b, 0, 200);
        $lenA = mb_strlen($a);
        $lenB = mb_strlen($b);
        if ($lenA === 0 || $lenB === 0) return 0;
        similar_text($a, $b, $pct);
        return $pct;
    }

    // ── Highlight extraction ──

    private function extractHighlights(string $text): array
    {
        $lines = explode("\n", $text);
        $highlights = [];
        $keywords = ['结论', '决定', '注意', '方案', '原因', '建议', '核心', '关键', '最终', '→', '=>', '总结'];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') continue;
            foreach ($keywords as $kw) {
                if (mb_strpos($trimmed, $kw) !== false) {
                    $highlights[] = $trimmed;
                    break;
                }
            }
        }

        return array_slice($highlights, 0, 20);
    }
}
