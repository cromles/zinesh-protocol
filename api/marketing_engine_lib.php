<?php
declare(strict_types=1);

require_once __DIR__ . '/wallet_lib.php';

function zinesh_marketing_config(): array
{
    return zinesh_config()['marketing'] ?? [];
}

function zinesh_marketing_data_dir(): string
{
    $dir = zinesh_data_path('marketing');
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    return $dir;
}

function zinesh_marketing_leads_path(): string
{
    return zinesh_marketing_data_dir() . '/radar_leads.jsonl';
}

function zinesh_marketing_state_path(): string
{
    return zinesh_marketing_data_dir() . '/radar_state.json';
}

function zinesh_marketing_read_state(): array
{
    $path = zinesh_marketing_state_path();
    if (!file_exists($path)) {
        return [];
    }
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function zinesh_marketing_write_state(array $state): void
{
    $state['updatedAt'] = date('c');
    file_put_contents(
        zinesh_marketing_state_path(),
        json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
    @chmod(zinesh_marketing_state_path(), 0640);
}

function zinesh_marketing_lead_ids_index(int $maxLines = 5000): array
{
    $path = zinesh_marketing_leads_path();
    if (!file_exists($path)) {
        return [];
    }
    $ids = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    if (count($lines) > $maxLines) {
        $lines = array_slice($lines, -$maxLines);
    }
    foreach ($lines as $line) {
        $row = json_decode($line, true);
        if (!is_array($row)) {
            continue;
        }
        $id = (string)($row['id'] ?? '');
        if ($id !== '') {
            $ids[$id] = true;
        }
    }
    return $ids;
}

function zinesh_marketing_append_lead(array $lead): bool
{
    $id = (string)($lead['id'] ?? '');
    if ($id === '') {
        return false;
    }
    static $index = null;
    if ($index === null) {
        $index = zinesh_marketing_lead_ids_index();
    }
    if (isset($index[$id])) {
        return false;
    }

    $lead['foundAt'] = $lead['foundAt'] ?? date('c');
    $line = json_encode($lead, JSON_UNESCAPED_UNICODE);
    file_put_contents(zinesh_marketing_leads_path(), $line . "\n", FILE_APPEND | LOCK_EX);
    @chmod(zinesh_marketing_leads_path(), 0640);
    $index[$id] = true;
    return true;
}

/** @return array<int,array<string,mixed>> */
function zinesh_marketing_recent_leads(int $limit = 20): array
{
    $path = zinesh_marketing_leads_path();
    if (!file_exists($path)) {
        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $lines = array_slice($lines, -$limit);
    $out = [];
    foreach ($lines as $line) {
        $row = json_decode($line, true);
        if (is_array($row)) {
            $out[] = $row;
        }
    }
    return array_reverse($out);
}

function zinesh_marketing_count_leads(): int
{
    $path = zinesh_marketing_leads_path();
    if (!file_exists($path)) {
        return 0;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return is_array($lines) ? count($lines) : 0;
}

function zinesh_marketing_x_bearer_token(): string
{
    $secrets = zinesh_load_server_secrets();
    $cfg = zinesh_marketing_config();
    return trim((string)($secrets['x_bearer_token'] ?? $cfg['x_bearer_token'] ?? ''));
}

/** @return string[] */
function zinesh_marketing_keywords_tr(): array
{
    $cfg = zinesh_marketing_config();
    $keywords = $cfg['keywords_tr'] ?? [];
    if (!is_array($keywords) || $keywords === []) {
        $keywords = [
            'ödeme alamadım',
            'paramı alamadım',
            'freelance dolandırıldım',
            'escrow',
            'güvenli ödeme',
            'upwork alternatif',
            'freelancer güven',
            'iş veren ödeme yapmadı',
            'teminat',
            'aracı ödeme',
        ];
    }
    return array_values(array_unique(array_filter(array_map(static fn($k) => trim((string)$k), $keywords))));
}

function zinesh_marketing_score_text(string $text, string $keyword): float
{
    $lower = mb_strtolower($text, 'UTF-8');
    $score = 0.35;
    if (mb_strpos($lower, mb_strtolower($keyword, 'UTF-8')) !== false) {
        $score += 0.25;
    }
    $pain = ['dolandır', 'mağdur', 'ödeme', 'güven', 'teminat', 'escrow', 'freelance', 'param', 'iş'];
    foreach ($pain as $word) {
        if (mb_strpos($lower, $word) !== false) {
            $score += 0.05;
        }
    }
    return min(1.0, round($score, 2));
}

function zinesh_marketing_http_get(string $url, array $headers = []): ?array
{
    $headerLines = "Accept: application/json\r\nUser-Agent: ZineshMarketingEngine/1.0\r\n";
    foreach ($headers as $k => $v) {
        $headerLines .= "{$k}: {$v}\r\n";
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => $headerLines,
            'timeout' => 45,
            'ignore_errors' => true,
        ],
    ]);
    $res = @file_get_contents($url, false, $ctx);
    if ($res === false) {
        return null;
    }
    $json = json_decode($res, true);
    return is_array($json) ? $json : null;
}

function zinesh_marketing_build_x_query(array $keywords): string
{
    $parts = [];
    foreach ($keywords as $keyword) {
        $keyword = trim($keyword);
        if ($keyword === '') {
            continue;
        }
        $safe = str_replace('"', '', $keyword);
        $parts[] = '"' . $safe . '"';
    }
    if ($parts === []) {
        return 'lang:tr -is:retweet';
    }
    return '(' . implode(' OR ', $parts) . ') lang:tr -is:retweet';
}

/**
 * X (Twitter) recent search — x_bearer_token gerekir (server_secrets.json).
 *
 * @return array{ok:bool,newLeads:int,scanned:int,error?:string,query?:string}
 */
function zinesh_marketing_radar_scan(): array
{
    $token = zinesh_marketing_x_bearer_token();
    $keywords = zinesh_marketing_keywords_tr();
    $query = zinesh_marketing_build_x_query($keywords);
    $state = zinesh_marketing_read_state();
    $result = [
        'ok' => false,
        'newLeads' => 0,
        'scanned' => 0,
        'query' => $query,
    ];

    if ($token === '') {
        $result['error'] = 'x_bearer_token_missing';
        $state['lastRun'] = date('c');
        $state['lastError'] = $result['error'];
        zinesh_marketing_write_state($state);
        return $result;
    }

    $params = http_build_query([
        'query' => $query,
        'max_results' => 20,
        'tweet.fields' => 'created_at,author_id,lang,public_metrics',
        'expansions' => 'author_id',
        'user.fields' => 'username,name',
    ]);
    $url = 'https://api.twitter.com/2/tweets/search/recent?' . $params;
    $payload = zinesh_marketing_http_get($url, ['Authorization' => 'Bearer ' . $token]);
    if (!is_array($payload)) {
        $result['error'] = 'x_api_unreachable';
        $state['lastRun'] = date('c');
        $state['lastError'] = $result['error'];
        zinesh_marketing_write_state($state);
        return $result;
    }
    if (!empty($payload['errors'])) {
        $result['error'] = 'x_api_error';
        $result['details'] = $payload['errors'];
        $state['lastRun'] = date('c');
        $state['lastError'] = json_encode($payload['errors'], JSON_UNESCAPED_UNICODE);
        zinesh_marketing_write_state($state);
        return $result;
    }

    $usersById = [];
    foreach (($payload['includes']['users'] ?? []) as $user) {
        if (!is_array($user)) {
            continue;
        }
        $usersById[(string)($user['id'] ?? '')] = $user;
    }

    $newLeads = 0;
    foreach (($payload['data'] ?? []) as $tweet) {
        if (!is_array($tweet)) {
            continue;
        }
        $result['scanned']++;
        $tweetId = (string)($tweet['id'] ?? '');
        $text = (string)($tweet['text'] ?? '');
        $authorId = (string)($tweet['author_id'] ?? '');
        $author = $usersById[$authorId] ?? [];
        $username = (string)($author['username'] ?? 'unknown');

        $matchedKeyword = $keywords[0] ?? 'genel';
        $lower = mb_strtolower($text, 'UTF-8');
        foreach ($keywords as $keyword) {
            if (mb_strpos($lower, mb_strtolower($keyword, 'UTF-8')) !== false) {
                $matchedKeyword = $keyword;
                break;
            }
        }

        $lead = [
            'id' => 'x:' . $tweetId,
            'platform' => 'x',
            'tweetId' => $tweetId,
            'author' => '@' . $username,
            'authorName' => (string)($author['name'] ?? ''),
            'text' => $text,
            'url' => 'https://x.com/' . rawurlencode($username) . '/status/' . rawurlencode($tweetId),
            'keyword' => $matchedKeyword,
            'score' => zinesh_marketing_score_text($text, $matchedKeyword),
            'lang' => (string)($tweet['lang'] ?? 'tr'),
            'createdAt' => (string)($tweet['created_at'] ?? ''),
        ];
        if (zinesh_marketing_append_lead($lead)) {
            $newLeads++;
        }
    }

    $result['ok'] = true;
    $result['newLeads'] = $newLeads;
    $state['lastRun'] = date('c');
    $state['lastError'] = null;
    $state['lastNewLeads'] = $newLeads;
    $state['lastScanned'] = $result['scanned'];
    $state['totalLeads'] = zinesh_marketing_count_leads();
    zinesh_marketing_write_state($state);

    return $result;
}

function zinesh_marketing_radar_summary_text(): string
{
    $state = zinesh_marketing_read_state();
    $total = zinesh_marketing_count_leads();
    $recent = zinesh_marketing_recent_leads(5);
    $lines = [
        '<b>Pazar Radarı (X)</b>',
        'toplam lead: ' . $total,
        'son tarama: ' . (string)($state['lastRun'] ?? '—'),
        'son yeni: ' . (int)($state['lastNewLeads'] ?? 0),
    ];
    if (!empty($state['lastError'])) {
        $lines[] = 'uyarı: ' . htmlspecialchars((string)$state['lastError'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    if ($recent !== []) {
        $lines[] = '';
        $lines[] = '<b>Son kayıtlar</b>';
        foreach ($recent as $lead) {
            $snippet = mb_substr((string)($lead['text'] ?? ''), 0, 120);
            $lines[] = '• ' . htmlspecialchars((string)($lead['author'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . ' — ' . htmlspecialchars($snippet, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }
    return implode("\n", $lines);
}
