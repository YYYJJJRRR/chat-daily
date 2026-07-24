<?php

class OpencodeReader
{
    private SQLite3 $db;

    public function __construct()
    {
        $dbPath = getenv('LOCALAPPDATA') . '\\opencode\\opencode.db';
        if (!file_exists($dbPath)) {
            $dbPath = getenv('USERPROFILE') . '\\.local\\share\\opencode\\opencode.db';
        }
        $this->db = new SQLite3($dbPath);
    }

    public function getSessions(string $date = null): array
    {
        $date = $date ?: date('Y-m-d');
        $dayStart = strtotime($date . ' 00:00:00') * 1000;
        $dayEnd = $dayStart + 86400000;

        // Get sessions created on this date
        $stmt = $this->db->prepare(
            "SELECT DISTINCT s.id, s.title, s.time_created, s.time_updated, s.agent, s.model
             FROM session s
             WHERE s.time_created >= :start AND s.time_created < :end
             ORDER BY s.time_created ASC"
        );
        $stmt->bindValue(':start', $dayStart, SQLITE3_INTEGER);
        $stmt->bindValue(':end', $dayEnd, SQLITE3_INTEGER);
        $result = $stmt->execute();

        $seen = [];
        $sessions = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $seen[$row['id']] = true;
            $sessions[] = $row;
        }

        // Also get sessions that have part activity on this date
        // (catches long-running sessions that span multiple days)
        $stmt = $this->db->prepare(
            "SELECT DISTINCT s.id, s.title, s.time_created, s.time_updated, s.agent, s.model
             FROM session s
             JOIN part p ON p.session_id = s.id
             WHERE p.time_created >= :start AND p.time_created < :end
               AND s.id NOT IN (SELECT id FROM session WHERE time_created >= :start2 AND time_created < :end2)
             ORDER BY s.time_created ASC"
        );
        $stmt->bindValue(':start', $dayStart, SQLITE3_INTEGER);
        $stmt->bindValue(':end', $dayEnd, SQLITE3_INTEGER);
        $stmt->bindValue(':start2', $dayStart, SQLITE3_INTEGER);
        $stmt->bindValue(':end2', $dayEnd, SQLITE3_INTEGER);
        $result = $stmt->execute();

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if (!isset($seen[$row['id']])) {
                $seen[$row['id']] = true;
                $sessions[] = $row;
            }
        }

        return $sessions;
    }

    public function getSessionMeta(string $sessionId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, title, time_created, time_updated, agent, model
             FROM session WHERE id = :sid"
        );
        $stmt->bindValue(':sid', $sessionId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        return $row ?: null;
    }

    public function getMessages(string $sessionId): array
    {
        $stmt = $this->db->prepare(
            "SELECT m.id, m.time_created, m.data
             FROM message m
             WHERE m.session_id = :sid
             ORDER BY m.time_created ASC"
        );
        $stmt->bindValue(':sid', $sessionId, SQLITE3_TEXT);
        $result = $stmt->execute();

        $messages = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $meta = json_decode($row['data'], true) ?? [];
            $role = $meta['role'] ?? 'unknown';
            $parts = $this->getParts($row['id']);
            $text = $this->combineParts($parts);

            $messages[] = [
                'id'       => $row['id'],
                'role'     => $role,
                'time'     => $row['time_created'],
                'text'     => $text,
            ];
        }
        return $messages;
    }

    private function getParts(string $messageId): array
    {
        $stmt = $this->db->prepare(
            "SELECT data FROM part WHERE message_id = :mid ORDER BY time_created ASC"
        );
        $stmt->bindValue(':mid', $messageId, SQLITE3_TEXT);
        $result = $stmt->execute();

        $parts = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $data = json_decode($row['data'], true) ?? [];
            $parts[] = $data;
        }
        return $parts;
    }

    private function combineParts(array $parts): string
    {
        $texts = [];
        $inCodeBlock = false;

        foreach ($parts as $p) {
            $type = $p['type'] ?? '';

            if ($type === 'text' && isset($p['text'])) {
                $texts[] = $p['text'];
            } elseif ($type === 'tool-start' || $type === 'step-start') {
                continue;
            } elseif ($type === 'tool-end' || $type === 'step-end') {
                continue;
            } elseif ($type === 'tool-use') {
                $name = $p['name'] ?? 'tool';
                $input = json_encode($p['input'] ?? '', JSON_UNESCAPED_UNICODE);
                $texts[] = "[调用工具: {$name}]";
            } elseif ($type === 'tool-result') {
                $texts[] = "[工具返回: " . mb_substr($p['content'] ?? '', 0, 200) . "]";
            }
        }

        return implode("\n", $texts);
    }

    public function formatConversation(array $messages): string
    {
        $lines = [];
        foreach ($messages as $msg) {
            $role = $msg['role'] === 'user' ? '**我:**' : '**AI:**';
            $text = trim($msg['text']);
            if ($text === '') continue;
            $lines[] = $role . ' ' . $text;
        }
        return implode("\n\n", $lines);
    }
}
