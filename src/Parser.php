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
                        $codeBlocks[] = ['lang' => $codeBlockLang ?: '', 'code' => $code];
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
                $todos[] = ['done' => trim($m[1]) === 'x', 'text' => trim($m[2])];
            }

            $currentContent[] = $line;
        }

        if ($currentRole !== null && count($currentContent) > 0) {
            $rounds[] = [
                'role'    => $currentRole,
                'content' => $this->cleanContent($currentContent),
            ];
        }

        // Compression pipeline
        $rounds = $this->filterPoliteness($rounds);
        $rounds = $this->removeToolBoilerplate($rounds);
        $rounds = $this->compressAiResponses($rounds);
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
        if (preg_match('/^\*\*(你|我|user|you|human)\s*[:：]\*\*/i', $line)) return 'user';
        if (preg_match('/^\*\*(ai|assistant|chatgpt|gpt|model|bot)\s*[:：]\*\*/i', $line)) return 'assistant';
        return null;
    }

    private function cleanContent(array $lines): string
    {
        $result = preg_replace('/^\*\*(你|我|user|you|human|ai|assistant|chatgpt|gpt|model|bot)\s*[:：]\*\*(\s*)/i', '', $lines[0], 1);
        $lines[0] = $result;
        $lines = array_filter($lines, function ($line) {
            $trimmed = trim($line);
            if ($trimmed === '') return false;
            $stripped = preg_replace('/[\s\p{P}\p{S}]/u', '', $trimmed);
            return $stripped !== '';
        });
        return implode("\n", array_values($lines));
    }

    // ── Compression pipeline ──

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
                foreach ($patterns as $p) {
                    if (preg_match($p, $trimmed)) {
                        if (mb_strlen(trim(preg_replace($p, '', $trimmed))) < 5) return false;
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

    private function compressAiResponses(array $rounds): array
    {
        foreach ($rounds as &$round) {
            if ($round['role'] !== 'assistant') continue;

            $lines = explode("\n", $round['content']);
            $filtered = [];
            $hasCode = false;
            $inCode = false;

            foreach ($lines as $line) {
                $trimmed = trim($line);

                // Track if output has code blocks
                if (str_starts_with($trimmed, '```')) {
                    $inCode = !$inCode;
                    $hasCode = true;
                    $filtered[] = $line;
                    continue;
                }
                if ($inCode) { $filtered[] = $line; continue; }

                // Remove verbose AI introductory phrases
                if ($this->isVerboseIntro($trimmed)) continue;

                // Remove restatements of the question
                if ($this->isRestatement($trimmed)) continue;

                // Remove "here's the summary" type meta-commentary
                if (preg_match('/^(以上|下面是|以下是|总的来说|总而言之|综上所述)/iu', $trimmed)) continue;

                $filtered[] = $line;
            }

            // If the response has code blocks and little else, keep just the code
            if ($hasCode) {
                $codeLines = array_filter($filtered, fn($l) => str_starts_with(trim($l), '```') || $inCode);
                // But also keep key explanations that are short (< 100 chars)
                $keepExplanations = [];
                foreach ($filtered as $l) {
                    if (!str_starts_with(trim($l), '```') && mb_strlen(trim($l)) < 100) {
                        $keepExplanations[] = $l;
                    }
                }
                // If there are short explanations, include them before the code
                if ($keepExplanations) {
                    $filtered = array_merge($keepExplanations, array_filter($filtered, fn($l) => str_starts_with(trim($l), '```')));
                } else {
                    $filtered = array_values(array_filter($filtered, fn($l) => str_starts_with(trim($l), '```') || $inCode));
                }
            }

            $round['content'] = implode("\n", array_values($filtered));
        }
        return $rounds;
    }

    private function isVerboseIntro(string $line): bool
    {
        $patterns = [
            '/^(好的|好的，|可以的|当然可以|没问题|让我|我来|我建议|我推荐|根据|基于|针对|对于)/iu',
            '/^(Look|Let|Based|Here|I\'ll|I can|You can|This is|The following|In order)/iu',
            '/^(可以。|以下是|下面是|这是一个)/u',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $line)) return true;
        }
        return false;
    }

    private function isRestatement(string $line): bool
    {
        // Lines that just restate the user's question before answering
        $restatementKeywords = ['您需要', '你想要', '你问', '你想', '你提到', '你要求', '这里', '这个功能', '这个需求'];
        foreach ($restatementKeywords as $kw) {
            if (mb_strpos($line, $kw) !== false && mb_strlen($line) < 50) return true;
        }
        return false;
    }

    private function mergeSimilarAdjacent(array $rounds): array
    {
        $merged = [];
        $prev = null;
        foreach ($rounds as $r) {
            if ($prev === null) { $prev = $r; continue; }
            if ($prev['role'] === $r['role'] && $this->textSimilarity($prev['content'], $r['content']) > 70) {
                $prev['content'] = $prev['content'] . "\n" . $r['content'];
            } else {
                $merged[] = $prev;
                $prev = $r;
            }
        }
        if ($prev !== null) $merged[] = $prev;
        return $merged;
    }

    private function textSimilarity(string $a, string $b): float
    {
        $a = mb_substr($a, 0, 200);
        $b = mb_substr($b, 0, 200);
        if (mb_strlen($a) === 0 || mb_strlen($b) === 0) return 0;
        similar_text($a, $b, $pct);
        return $pct;
    }

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
