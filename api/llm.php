<?php
declare(strict_types=1);

/**
 * Единый вызов языковой модели для всей CRM: подбор, автоответы, шильдик, Директ.
 *
 * Провайдеры:
 *   deepseek  — OpenAI-совместимый /chat/completions (api.deepseek.com), модель deepseek-v4-flash.
 *               У v4 «думающие» модели: reasoning съедает max_tokens и content приходит пустым,
 *               поэтому thinking глушится явно. Картинки и PDF не принимает.
 *   anthropic — Messages API, умеет vision/PDF (фото шильдика, PDF-заявки).
 *
 * Выбор: crm_settings.ai_provider = auto | anthropic | deepseek. В auto берётся DeepSeek,
 * если задан его ключ, иначе Anthropic. Вызов С ВЛОЖЕНИЯМИ всегда идёт в Anthropic —
 * без его ключа честно бросаем понятную ошибку, а не шлём фото туда, где его не прочтут.
 *
 * До этого файла тот же cURL к Anthropic был скопирован в четырёх местах
 * (ai.php, ai_auto.php, autoreply.php, direct.php) — теперь все они зовут llm_call().
 */

require_once __DIR__ . '/helpers.php';

function llm_key(string $provider): string {
    return $provider === 'deepseek'
        ? secret('deepseek_api_key', (string)(cfg()['deepseek']['api_key'] ?? ''))
        : secret('anthropic_api_key', (string)(cfg()['anthropic']['api_key'] ?? ''));
}

/** Какой провайдер реально будет использован (с учётом ключей). */
function llm_provider(bool $needVision = false): string {
    if ($needVision) return 'anthropic';
    $want = strtolower(trim((string)setting('ai_provider', 'auto')));
    if ($want === 'deepseek' || $want === 'anthropic') return $want;
    return llm_key('deepseek') !== '' ? 'deepseek' : 'anthropic';
}

/** Настроен ли ИИ хоть каким-то ключом. */
function llm_ready(bool $needVision = false): bool {
    return llm_key(llm_provider($needVision)) !== '';
}

/** Подпись для интерфейса: «DeepSeek · deepseek-v4-flash». */
function llm_label(): string {
    $p = llm_provider();
    return $p === 'deepseek'
        ? 'DeepSeek · ' . secret('deepseek_model', 'deepseek-v4-flash')
        : 'Claude · ' . secret('anthropic_model', (string)(cfg()['anthropic']['model'] ?? 'claude-opus-4-8'));
}

/**
 * Вызов модели. $attachments — content-блоки Anthropic (image/document) из ai_attachment_block().
 * Возвращает текст ответа или бросает RuntimeException с человеческим текстом.
 */
function llm_call(string $system, string $userText, int $maxTokens = 1500, array $attachments = [], int $timeout = 0): string {
    $provider = llm_provider($attachments !== []);
    $key = llm_key($provider);
    if ($key === '') {
        throw new RuntimeException($attachments
            ? 'Для фото и PDF нужен ключ Anthropic (Настройки → Интеграции): DeepSeek вложения не читает'
            : 'ИИ не настроен: добавьте ключ DeepSeek или Anthropic в Настройки → Интеграции');
    }
    $maxTokens = max(200, $maxTokens);

    if ($provider === 'deepseek') {
        $model = secret('deepseek_model', (string)(cfg()['deepseek']['model'] ?? 'deepseek-v4-flash'));
        $base  = rtrim(secret('deepseek_base_url', (string)(cfg()['deepseek']['base_url'] ?? 'https://api.deepseek.com')), '/');
        $url   = $base . '/chat/completions';
        $headers = ['content-type: application/json', 'authorization: Bearer ' . $key];
        $payload = [
            'model'       => $model,
            'max_tokens'  => $maxTokens,
            'temperature' => 0.3,
            'messages'    => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $userText]],
        ];
        if (str_starts_with($model, 'deepseek-v4')) $payload['thinking'] = ['type' => 'disabled'];
    } else {
        $model = secret('anthropic_model', (string)(cfg()['anthropic']['model'] ?? 'claude-opus-4-8'));
        $url = 'https://api.anthropic.com/v1/messages';
        $headers = ['content-type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'];
        $payload = [
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'system'     => $system,
            'messages'   => [['role' => 'user', 'content' => $attachments
                ? array_merge($attachments, [['type' => 'text', 'text' => $userText]]) : $userText]],
        ];
    }
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

    // Ретраи: 429 и 5xx (в т.ч. 529 overloaded) и сетевые сбои штатны для LLM-API.
    $raw = false; $http = 0; $err = '';
    // $timeout > 0 — быстрый режим (чат на сайте): одна попытка, жёсткий срок, без ретраев.
    $tries = $timeout > 0 ? 1 : 3;
    for ($attempt = 1; $attempt <= $tries; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $body,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => $timeout > 0 ? $timeout : 120,
        ]);
        $raw  = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        $retryable = ($raw === false) || $http === 429 || $http >= 500;
        if (!$retryable || $attempt === $tries) break;
        sleep($attempt * 2);
    }
    if ($raw === false) throw new RuntimeException('Сеть/cURL: ' . $err);
    $data = json_decode((string)$raw, true);
    $name = $provider === 'deepseek' ? 'DeepSeek API' : 'Claude API';
    if ($http !== 200) {
        throw new RuntimeException($name . ': ' . ($data['error']['message'] ?? ('HTTP ' . $http)));
    }

    if ($provider === 'deepseek') {
        $choice = $data['choices'][0] ?? [];
        $text = trim((string)($choice['message']['content'] ?? ''));
        if (($choice['finish_reason'] ?? '') === 'length' && $text === '') {
            throw new RuntimeException('Ответ модели обрезан по лимиту токенов — увеличьте max_tokens.');
        }
    } else {
        if (($data['stop_reason'] ?? '') === 'refusal') throw new RuntimeException('Запрос отклонён моделью (refusal).');
        if (($data['stop_reason'] ?? '') === 'max_tokens') {
            throw new RuntimeException('Ответ модели обрезан по лимиту токенов — упростите заявку или увеличьте max_tokens.');
        }
        $text = '';
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') $text .= $block['text'];
        }
        $text = trim($text);
    }
    if ($text === '') throw new RuntimeException('Пустой ответ модели.');
    return $text;
}
