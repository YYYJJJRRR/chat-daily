<?php

require_once __DIR__ . '/Parser.php';
require_once __DIR__ . '/OpencodeReader.php';
require_once __DIR__ . '/Storage.php';
require_once __DIR__ . '/Generator.php';

$body = json_decode(file_get_contents('php://input'), true) ?? [];

switch (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) {
    case '/api/parse':
        $text = $body['text'] ?? '';
        if (!trim($text)) {
            echo json_encode(['error' => '请输入对话内容']);
            exit;
        }
        $parser = new Parser();
        $result = $parser->parse($text);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        break;

    case '/api/sessions':
        try {
            $reader = new OpencodeReader();
            $date = $body['date'] ?? date('Y-m-d');
            $sessions = $reader->getSessions($date);
            echo json_encode(['sessions' => $sessions, 'date' => $date], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            echo json_encode(['error' => '无法读取对话记录: ' . $e->getMessage()]);
        }
        break;

    case '/api/load-session':
        try {
            $reader = new OpencodeReader();
            $sessionId = $body['session_id'] ?? '';
            if (!$sessionId) {
                echo json_encode(['error' => '缺少 session_id']);
                exit;
            }
            $messages = $reader->getMessages($sessionId);
            $conversation = $reader->formatConversation($messages);
            $meta = $reader->getSessionMeta($sessionId);
            echo json_encode([
                'session_id'   => $sessionId,
                'messages'     => $messages,
                'conversation' => $conversation,
                'meta'         => $meta,
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            echo json_encode(['error' => '加载失败: ' . $e->getMessage()]);
        }
        break;

    // === Phase 1: Save & Generate ===

    case '/api/save-entry':
        $date = $body['date'] ?? date('Y-m-d');
        $source = $body['source'] ?? 'manual';
        $parsed = $body['parsed'] ?? [];
        $sessionId = $body['session_id'] ?? null;
        if (empty($parsed)) {
            echo json_encode(['error' => '缺少 parsed 数据']);
            exit;
        }
        $storage = new Storage();
        $id = $storage->saveEntry($date, $source, $parsed, $sessionId);
        echo json_encode(['id' => $id, 'date' => $date]);
        break;

    case '/api/generate-daily':
        $date = $body['date'] ?? date('Y-m-d');
        $storage = new Storage();
        $entries = $storage->getEntries($date);
        if (empty($entries)) {
            echo json_encode(['error' => "{$date} 没有已保存的条目"]);
            exit;
        }
        $generator = new DailyGenerator();
        $path = $generator->generate($date, $entries);
        $content = file_get_contents($path);
        echo json_encode([
            'date'    => $date,
            'path'    => $path,
            'content' => $content,
            'entries' => count($entries),
        ], JSON_UNESCAPED_UNICODE);
        break;

    case '/api/daily-list':
        $storage = new Storage();
        $days = $storage->listDays();
        echo json_encode(['days' => $days], JSON_UNESCAPED_UNICODE);
        break;

    case '/api/get-daily':
        $date = $body['date'] ?? '';
        if (!$date) {
            echo json_encode(['error' => '缺少 date']);
            exit;
        }
        $generator = new DailyGenerator();
        $content = $generator->getDaily($date);
        if ($content === null) {
            echo json_encode(['error' => "{$date} 日报不存在"]);
            exit;
        }
        echo json_encode(['date' => $date, 'content' => $content], JSON_UNESCAPED_UNICODE);
        break;

    // ── Phase 4: Weekly / Monthly ──

    case '/api/generate-weekly':
        $date = $body['date'] ?? date('Y-m-d');
        $ts = strtotime($date);
        $dayOfWeek = date('w', $ts);
        $monday = date('Y-m-d', strtotime("-" . ($dayOfWeek ?: 7) . " days", $ts));
        $sunday = date('Y-m-d', strtotime("+" . (6 - ($dayOfWeek ?: 7)) . " days", $ts));

        $storage = new Storage();
        $entries = $storage->getEntriesByRange($monday, $sunday);
        if (empty($entries)) {
            echo json_encode(['error' => "{$monday} ~ {$sunday} 没有条目"]);
            exit;
        }
        $generator = new DailyGenerator();
        $path = $generator->generateWeekly($monday, $sunday, $entries);
        echo json_encode([
            'range'   => "{$monday} ~ {$sunday}",
            'path'    => $path,
            'entries' => count($entries),
        ], JSON_UNESCAPED_UNICODE);
        break;

    case '/api/generate-monthly':
        $date = $body['date'] ?? date('Y-m-d');
        $yearMonth = date('Y-m', strtotime($date));
        $startDate = $yearMonth . '-01';
        $endDate = date('Y-m-t', strtotime($date));

        $storage = new Storage();
        $entries = $storage->getEntriesByRange($startDate, $endDate);
        if (empty($entries)) {
            echo json_encode(['error' => "{$yearMonth} 没有条目"]);
            exit;
        }
        $generator = new DailyGenerator();
        $path = $generator->generateMonthly($yearMonth, $entries);
        echo json_encode([
            'month'   => $yearMonth,
            'path'    => $path,
            'entries' => count($entries),
        ], JSON_UNESCAPED_UNICODE);
        break;

    default:
        http_response_code(404);
        echo json_encode(['error' => 'Not Found']);
}
