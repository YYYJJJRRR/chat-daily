<?php

require_once __DIR__ . '/Parser.php';
require_once __DIR__ . '/OpencodeReader.php';

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

    default:
        http_response_code(404);
        echo json_encode(['error' => 'Not Found']);
}
