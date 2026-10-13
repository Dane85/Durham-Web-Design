<?php
// telegram-webhook.php — Real-Time Interactive Ops Bot Webhook for Dane
header('Content-Type: application/json; charset=utf-8');

$envFile = '/etc/epic-ops.env';
$botToken = '';
$allowedChatId = '6583411618';

if (file_exists($envFile)) {
    $env = @parse_ini_file($envFile);
    $botToken = $env['TELEGRAM_BOT_TOKEN'] ?? '';
    if (!empty($env['TELEGRAM_CHAT_ID'])) {
        $allowedChatId = strval($env['TELEGRAM_CHAT_ID']);
    }
}

// Read raw incoming update from Telegram
$input = file_get_contents('php://input');
$update = json_decode($input, true);

if (!$update || !isset($update['message'])) {
    echo json_encode(['ok' => true]);
    exit;
}

$message = $update['message'];
$chatId = strval($message['chat']['id'] ?? '');
$text = trim($message['text'] ?? '');

// Security: Restrict strictly to Dane's Chat ID
if ($chatId !== $allowedChatId) {
    if (!empty($botToken)) {
        $denyMsg = "⛔ <b>Access Denied:</b> This bot is restricted to authorized Epic Expressions operations.";
        sendTelegramDirect($botToken, $chatId, $denyMsg);
    }
    echo json_encode(['ok' => true]);
    exit;
}

// Command Parsing (handle /command, /command@Epicexx_bot, or plain text)
$cmd = strtolower($text);
if (strpos($cmd, '@') !== false) {
    $cmd = explode('@', $cmd)[0];
}

// 1. /status command
if ($cmd === '/status' || $cmd === 'status') {
    if (file_exists('/usr/local/bin/send-ops-status.sh')) {
        @exec('/usr/local/bin/send-ops-status.sh ondemand 2>&1');
    }
    echo json_encode(['ok' => true]);
    exit;
}

// 2. /ping command
if ($cmd === '/ping' || $cmd === 'ping') {
    $pingMsg = "🏓 <b>Pong!</b>\n\n"
        . "🖥️ <b>Hetzner Node:</b> <code>5.161.161.222</code> (Ashburn CPX 11)\n"
        . "⏱️ <b>Server Time:</b> " . date('Y-m-d H:i:s T') . "\n"
        . "🟢 Webhook and daemon responsiveness: <b>100% nominal</b>.\n\n"
        . "Send <code>/status</code> for full system telemetry.";
    sendTelegramDirect($botToken, $chatId, $pingMsg);
    echo json_encode(['ok' => true]);
    exit;
}

// 3. /leads command
if ($cmd === '/leads' || $cmd === 'leads') {
    $storeFile = __DIR__ . '/data/store.json';
    $leads = [];
    if (file_exists($storeFile)) {
        $store = json_decode(file_get_contents($storeFile), true);
        $leads = $store['leads'] ?? [];
    }

    if (empty($leads)) {
        $leadsMsg = "💼 <b>Inbound Leads:</b> No leads currently in tracker.";
    } else {
        $count = count($leads);
        $leadsMsg = "💼 <b>INBOUND LEADS TRACKER ({$count} Total)</b>\n\n";
        $topLeads = array_slice($leads, 0, 5);
        foreach ($topLeads as $i => $l) {
            $num = $i + 1;
            $biz = htmlspecialchars($l['business'] ?? 'Lead #' . $num, ENT_QUOTES, 'UTF-8');
            $contact = htmlspecialchars($l['contact'] ?? 'Unknown', ENT_QUOTES, 'UTF-8');
            $phone = htmlspecialchars($l['phone'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
            $score = htmlspecialchars($l['score'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
            $date = htmlspecialchars($l['date'] ?? '', ENT_QUOTES, 'UTF-8');
            $leadsMsg .= "<b>{$num}. {$biz}</b>\n";
            $leadsMsg .= "   👤 Contact: {$contact} | 📞 {$phone}\n";
            $leadsMsg .= "   📊 Score: {$score} | 📅 {$date}\n\n";
        }
    }
    sendTelegramDirect($botToken, $chatId, $leadsMsg);
    echo json_encode(['ok' => true]);
    exit;
}

// 4. Default /help or /start
$helpMsg = "🤖 <b>Epic Expressions Operations Bot</b>\n\n"
    . "Available Commands:\n"
    . "• <code>/status</code> — Instant live fleet health, HTTP latencies & server telemetry\n"
    . "• <code>/ping</code> — Instant connectivity check\n"
    . "• <code>/leads</code> — View top recent speed audit inbound leads\n"
    . "• <code>/help</code> — Show this commands menu\n\n"
    . "<i>Automated reports dispatch daily at 9:00 AM & 9:00 PM EDT.</i>";
sendTelegramDirect($botToken, $chatId, $helpMsg);
echo json_encode(['ok' => true]);
exit;

function sendTelegramDirect($token, $chatId, $msg) {
    if (empty($token) || empty($chatId)) return;
    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $postData = http_build_query([
        'chat_id' => $chatId,
        'text' => $msg,
        'parse_mode' => 'HTML'
    ]);
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $postData,
            'timeout' => 5
        ]
    ]);
    @file_get_contents($url, false, $ctx);
}
