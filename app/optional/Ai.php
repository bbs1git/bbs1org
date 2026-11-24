<?php

declare(strict_types=1);

namespace app\optional;

if (!defined('APP_ROOT')) exit;

final class Ai
{
    public static function ready(): bool
    {
        return setting('ai_api_url', '') !== '' && setting('ai_api_key', '') !== '' && setting('ai_model', '') !== '';
    }

    public static function log_write(array $entry): int
    {
        try {
            q("INSERT INTO app_ai_logs(plugin_id,user_id,model,prompt_chars,completion_chars,prompt_tokens,completion_tokens,duration_ms,status,error,response_excerpt,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)", [
                cut((string)($entry['plugin_id'] ?? ''), 255),
                max(0, (int)($entry['user_id'] ?? 0)),
                cut((string)($entry['model'] ?? ''), 255),
                max(0, (int)($entry['prompt_chars'] ?? 0)),
                max(0, (int)($entry['completion_chars'] ?? 0)),
                max(0, (int)($entry['prompt_tokens'] ?? 0)),
                max(0, (int)($entry['completion_tokens'] ?? 0)),
                max(0, (int)($entry['duration_ms'] ?? 0)),
                cut((string)($entry['status'] ?? ''), 255),
                cut((string)($entry['error'] ?? ''), DB_TEXT_MAX_LENGTH),
                cut((string)($entry['response_excerpt'] ?? ''), 2000),
                now(),
            ]);
            return (int)db()->lastInsertId();
        } catch (Throwable) {
            return 0;
        }
    }

    private static function caller_plugin(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12) as $frame) {
            $file = str_replace('\\', '/', (string)($frame['file'] ?? ''));
            if ($file !== '' && str_starts_with($file, rtrim(str_replace('\\', '/', PLUGIN_DIR), '/') . '/')) return basename(dirname($file));
        }
        return '';
    }

    public static function chat(array $messages, array $options = []): array
    {
        $started = microtime(true);
        $plugin = self::caller_plugin();
        $model = trim((string)($options['model'] ?? setting('ai_model', '')));
        $prompt_chars = 0;
        foreach ($messages as $m) {
            if (!is_array($m)) continue;
            $content = $m['content'] ?? '';
            if (is_array($content)) {
                foreach ($content as $part) {
                    if (is_array($part) && (string)($part['type'] ?? '') === 'text') $prompt_chars += mb_strlen((string)($part['text'] ?? ''), 'UTF-8');
                }
            } else {
                $prompt_chars += mb_strlen((string)$content, 'UTF-8');
            }
        }
        $fail = static function (string $error, string $status = 'error', array $extra = []) use ($plugin, $model, $prompt_chars, $started): array {
            $duration_ms = (int)round((microtime(true) - $started) * 1000);
            $log_id = self::log_write(['plugin_id' => $plugin, 'user_id' => uid(), 'model' => $model, 'prompt_chars' => $prompt_chars, 'duration_ms' => $duration_ms, 'status' => $status, 'error' => $error] + $extra);
            if (debug_mode_enabled()) debug_log_write("ai_chat[{$plugin}]: {$status} {$error}");
            return ['ok' => false, 'content' => '', 'error' => $error, 'model' => $model, 'duration_ms' => $duration_ms, 'log_id' => $log_id, 'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0]];
        };
        if ($messages === [] || !is_array($messages[0] ?? null)) return $fail('消息格式不正确');
        $base = rtrim(trim(setting('ai_api_url', '')), '/');
        $key = trim(setting('ai_api_key', ''));
        if ($base === '' || $key === '' || $model === '') return $fail('AI 未配置：请在后台设置 AI 接口地址、API Key 和模型');
        if (preg_match('#^https?://#i', $base) !== 1) return $fail('AI 接口地址必须以 http(s):// 开头');
        $payload = [
            'model' => $model,
            'messages' => array_values(array_map(static function ($m): array {
                $content = is_array($m) ? ($m['content'] ?? '') : '';
                if (!is_array($content)) $content = (string)$content;
                return ['role' => (string)(is_array($m) ? ($m['role'] ?? 'user') : 'user'), 'content' => $content];
            }, $messages)),
            'temperature' => isset($options['temperature']) ? max(0, min(2, (float)$options['temperature'])) : max(0, min(2, (float)setting('ai_temperature', '0.7'))),
            'max_tokens' => isset($options['max_tokens']) ? max(1, min(200000, (int)$options['max_tokens'])) : max(1, min(200000, (int)setting('ai_max_tokens', '16384'))),
            'stream' => false,
        ];
        if (!empty($options['json'])) $payload['response_format'] = ['type' => 'json_object'];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($body === false) return $fail('请求数据编码失败');
        $timeout = isset($options['timeout']) ? max(1, min(300, (int)$options['timeout'])) : max(1, min(300, (int)setting('ai_timeout_seconds', '30')));
        $result = Plugin::remote_http_request($base . '/chat/completions', $timeout, ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . preg_replace('/[\r\n]+/', '', $key)], null, ['raw_body' => $body, 'max_bytes' => 4194304, 'connect_timeout' => min(10, $timeout)]);
        $duration_ms = (int)round((microtime(true) - $started) * 1000);
        $data = is_string($result['body'] ?? null) && ($result['body'] ?? '') !== '' ? json_decode((string)$result['body'], true) : null;
        if (!($result['ok'] ?? false)) {
            $message = '';
            if (is_array($data)) $message = (string)($data['error']['message'] ?? (!is_array($data['error'] ?? null) ? (string)($data['error'] ?? '') : ''));
            $error = trim($message) !== '' ? $message : (string)($result['error'] ?? '请求失败');
            return $fail('HTTP ' . (int)($result['status'] ?? 0) . '：' . $error, 'http_error', ['response_excerpt' => cut((string)($result['body'] ?? ''), 2000)]);
        }
        if (!is_array($data)) return $fail('AI 返回的不是有效 JSON', 'bad_response', ['response_excerpt' => cut((string)($result['body'] ?? ''), 2000)]);
        $content = trim((string)($data['choices'][0]['message']['content'] ?? ''));
        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
        $log = ['status' => 'ok', 'response_excerpt' => cut($content, 2000), 'prompt_tokens' => (int)($usage['prompt_tokens'] ?? 0), 'completion_tokens' => (int)($usage['completion_tokens'] ?? 0), 'completion_chars' => mb_strlen($content, 'UTF-8'), 'duration_ms' => $duration_ms];
        $log_id = self::log_write(['plugin_id' => $plugin, 'user_id' => uid(), 'model' => $model, 'prompt_chars' => $prompt_chars] + $log);
        if ($content === '') return $fail('AI 返回空内容', 'empty_response', ['duration_ms' => $duration_ms, 'log_id' => $log_id]);
        return ['ok' => true, 'content' => $content, 'error' => '', 'model' => $model, 'duration_ms' => $duration_ms, 'log_id' => $log_id, 'usage' => ['prompt_tokens' => $log['prompt_tokens'], 'completion_tokens' => $log['completion_tokens']]];
    }

    public static function chat_text(string $system, string $user, array $options = []): array
    {
        $messages = [];
        if (trim($system) !== '') $messages[] = ['role' => 'system', 'content' => $system];
        $messages[] = ['role' => 'user', 'content' => $user];
        return self::chat($messages, $options);
    }
}
