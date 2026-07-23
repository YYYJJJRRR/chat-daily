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
            // 检测代码块开始/结束
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

            // 检测对话轮次标记
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

            // 检测待办事项
            if (preg_match('/^-\s*\[([ x])\]\s*(.+)$/i', $line, $m)) {
                $todos[] = [
                    'done'  => trim($m[1]) === 'x',
                    'text'  => trim($m[2]),
                ];
            }

            $currentContent[] = $line;
        }

        // 最后一段
        if ($currentRole !== null && count($currentContent) > 0) {
            $rounds[] = [
                'role'    => $currentRole,
                'content' => $this->cleanContent($currentContent),
            ];
        }

        // 提取高亮句（含 →、结论、决定、注意 等关键词的行）
        $highlights = $this->extractHighlights($text);

        // 过滤礼貌段落
        $rounds = $this->filterPoliteness($rounds);

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
        if (preg_match('/^\*\*(你|我|user|you|human|ai|assistant)\s*[:：]\*\*/i', $line)) {
            return 'user';
        }
        if (preg_match('/^\*\*(ai|assistant|chatgpt|gpt|model)\s*[:：]\*\*/i', $line)) {
            return 'assistant';
        }
        return null;
    }

    private function cleanContent(array $lines): string
    {
        // 移除首行角色标记
        $result = preg_replace('/^\*\*(你|我|user|you|human|ai|assistant|chatgpt|gpt|model)\s*[:：]\*\*(\s*)/i', '', $lines[0], 1);
        $lines[0] = $result;

        // 移除纯表情/标点行
        $lines = array_filter($lines, function ($line) {
            $stripped = preg_replace('/[\s\p{P}\p{S}]/u', '', $line);
            return $stripped !== '';
        });

        return implode("\n", array_values($lines));
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

    private function filterPoliteness(array $rounds): array
    {
        $patterns = [
            '/^(谢谢|好的|好的，|明白|没问题|对的|是的|请|你好|hello|hi)[\s\p{P}]/iu',
            '/^(当然|可以的|可以。|这个没问题)/u',
        ];

        foreach ($rounds as &$round) {
            $lines = explode("\n", $round['content']);
            $lines = array_filter($lines, function ($line) use ($patterns) {
                $trimmed = trim($line);
                if ($trimmed === '') return false;
                foreach ($patterns as $p) {
                    if (preg_match($p, $trimmed)) return false;
                }
                return true;
            });
            $round['content'] = implode("\n", array_values($lines));
        }

        return array_filter($rounds, fn($r) => trim($r['content']) !== '');
    }
}
