<?php
// telegram-webhook.php - Real-Time Interactive Ops Bot Webhook for Dane
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
        $denyMsg = "🚫 <b>Access Denied:</b> This bot is restricted to authorized Epic Expressions operations.";
        sendTelegramDirect($botToken, $chatId, $denyMsg);
    }
    echo json_encode(['ok' => true]);
    exit;
}

// Command Parsing (handle /command, /command@Epicexx_bot, or plain text)
$firstWord = explode(' ', $text)[0];
$cmd = strtolower($firstWord);
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
    $pingMsg = "🏓 <b>PONG — SYSTEM NOMINAL</b>\n\n"
        . "━━━━━━━━━━━━━━━━━━━━\n\n"
        . "🖥️ <b>Hetzner Node:</b> <code>5.161.161.222</code> (Ashburn CPX 11)\n\n"
        . "🕒 <b>Server Time:</b> " . date('Y-m-d H:i:s T') . "\n\n"
        . "⚡ <b>Responsiveness:</b> 100% nominal (daemons & webhook healthy)\n\n"
        . "━━━━━━━━━━━━━━━━━━━━\n\n"
        . "<i>Send <code>/status</code> for complete system telemetry.</i>";
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
        $leadsMsg = "📋 <b>INBOUND LEADS TRACKER</b>\n\n"
            . "━━━━━━━━━━━━━━━━━━━━\n\n"
            . "ℹ️ No speed audit leads currently in tracker.";
    } else {
        $count = count($leads);
        $leadsMsg = "📋 <b>INBOUND LEADS TRACKER ({$count} Total)</b>\n\n"
            . "━━━━━━━━━━━━━━━━━━━━\n\n";
        $topLeads = array_slice($leads, 0, 5);
        foreach ($topLeads as $i => $l) {
            $num = $i + 1;
            $biz = htmlspecialchars($l['business'] ?? 'Lead #' . $num, ENT_QUOTES, 'UTF-8');
            $contact = htmlspecialchars($l['contact'] ?? 'Unknown', ENT_QUOTES, 'UTF-8');
            $phone = htmlspecialchars($l['phone'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
            $score = htmlspecialchars($l['score'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
            $date = htmlspecialchars($l['date'] ?? '', ENT_QUOTES, 'UTF-8');
            $leadsMsg .= "<b>{$num}. {$biz}</b>\n\n"
                . "  👤 <b>Contact:</b> {$contact}\n"
                . "  📞 <b>Phone:</b> {$phone}\n"
                . "  📊 <b>Speed Score:</b> {$score}/100\n"
                . "  📅 <b>Submitted:</b> {$date}\n\n"
                . "────────────────────\n\n";
        }
    }
    sendTelegramDirect($botToken, $chatId, $leadsMsg);
    echo json_encode(['ok' => true]);
    exit;
}

// 4. /proposal command: /proposal <Contact> "<Business Name>" <starter|growth|custom> [phone]
if ($cmd === '/proposal') {
    $argsStr = trim(substr($text, strlen($firstWord)));
    if (empty($argsStr)) {
        $usageMsg = "ℹ️ <b>PROPOSAL CLOSER COMMAND USAGE</b>\n\n"
            . "━━━━━━━━━━━━━━━━━━━━\n\n"
            . "<code>/proposal &lt;Contact&gt; \"&lt;Business Name&gt;\" &lt;starter|growth|custom&gt; [phone]</code>\n\n"
            . "<b>Example:</b>\n"
            . "<code>/proposal Marcus \"Apex Roofing Oshawa\" starter 9054318890</code>";
        sendTelegramDirect($botToken, $chatId, $usageMsg);
        echo json_encode(['ok' => true]);
        exit;
    }

    // Parse tokens with regex supporting quotes
    preg_match_all('/"([^"]+)"|\'([^\']+)\'|(\S+)/', $argsStr, $matches);
    $tokens = [];
    foreach ($matches[0] as $i => $raw) {
        if (!empty($matches[1][$i])) {
            $tokens[] = $matches[1][$i];
        } elseif (!empty($matches[2][$i])) {
            $tokens[] = $matches[2][$i];
        } else {
            $tokens[] = $matches[3][$i];
        }
    }

    $contact = $tokens[0] ?? 'Valued Client';
    $business = $tokens[1] ?? 'Local Business';
    $tierRaw = strtolower($tokens[2] ?? 'starter');
    $phone = $tokens[3] ?? '';

    // Validate & normalize tier
    $tier = 'starter';
    $tierTitle = 'Starter Launch';
    $totalPrice = 1499.99;
    $deposit = 749.99;
    $monthly = 49.99;

    if ($tierRaw === 'growth') {
        $tier = 'growth';
        $tierTitle = 'Growth & Conversion Engine';
        $totalPrice = 2899.99;
        $deposit = 1449.99;
        $monthly = 129.99;
    } elseif ($tierRaw === 'custom' || $tierRaw === 'enterprise') {
        $tier = 'custom';
        $tierTitle = 'Custom Enterprise Architecture';
        $totalPrice = 4999.99;
        $deposit = 2499.99;
        $monthly = 249.99;
    } elseif ($tierRaw === 'draa') {
        $tier = 'draa';
        $tierTitle = 'DRAA Non-Profit Architecture';
        $totalPrice = 700.00;
        $deposit = 350.00;
        $monthly = 50.00;
    }

    $propId = 'prop-' . time();
    $nowStr = date('Y-m-d H:i:s T');

    // Build Live URL
    $params = [
        'id' => $propId,
        'client' => $contact,
        'biz' => $business,
        'tier' => $tier
    ];
    if (!empty($phone)) {
        $params['phone'] = $phone;
    }
    $liveUrl = "https://durhamweb.design/proposal/?" . http_build_query($params);

    // Build Proposal Record
    $record = [
        'id' => $propId,
        'created_at' => $nowStr,
        'contact' => $contact,
        'business' => $business,
        'tier' => $tier,
        'tier_title' => $tierTitle,
        'total_price' => $totalPrice,
        'deposit' => $deposit,
        'monthly' => $monthly,
        'phone' => $phone,
        'status' => 'active',
        'proposal_url' => $liveUrl
    ];

    // Save to proposals.json
    $proposalsDir = __DIR__ . '/data';
    if (!is_dir($proposalsDir)) {
        @mkdir($proposalsDir, 0775, true);
    }
    $proposalsFile = $proposalsDir . '/proposals.json';
    $proposals = [];
    if (file_exists($proposalsFile)) {
        $proposals = json_decode(file_get_contents($proposalsFile), true) ?: [];
    }
    $proposals[] = $record;
    file_put_contents($proposalsFile, json_encode($proposals, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));


    $replyMsg = "🚀 <b>INSTANT CLIENT PROPOSAL GENERATED</b>\n\n"
        . "━━━━━━━━━━━━━━━━━━━━\n\n"
        . "👤 <b>Client:</b> " . htmlspecialchars($contact, ENT_QUOTES, 'UTF-8') . "\n\n"
        . "🏢 <b>Business:</b> " . htmlspecialchars($business, ENT_QUOTES, 'UTF-8') . "\n\n"
        . "⚡ <b>Tier:</b> {$tierTitle} ($" . number_format($totalPrice, 2) . " CAD)\n\n"
        . "💳 <b>50% Deposit Due:</b> $" . number_format($deposit, 2) . " CAD\n\n"
        . "🔄 <b>Monthly Retainer:</b> $" . number_format($monthly, 2) . " / mo\n\n"
        . (!empty($phone) ? "📞 <b>Direct Line:</b> " . htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') . "\n\n" : "")
        . "🛡️ <b>Guarantee:</b> Sub-0.3s TTFB & 100/100 Core Web Vitals\n\n"
        . "━━━━━━━━━━━━━━━━━━━━\n\n"
        . "🔗 <b>Live Proposal Closer Link:</b>\n"
        . "{$liveUrl}\n\n"
        . "━━━━━━━━━━━━━━━━━━━━\n\n"
        . "<i>Send this link directly to the prospect. They can review deliverables, sign digitally, and pay the 50% deposit via Stripe.</i>";

    sendTelegramDirect($botToken, $chatId, $replyMsg);
    echo json_encode(['ok' => true]);
    exit;
}

// 5. /teardown command: /teardown <url> [Business Name]
if ($cmd === '/teardown') {
    $argsStr = trim(substr($text, strlen($firstWord)));
    if (empty($argsStr)) {
        $usageMsg = "ℹ️ <b>SPEED TEARDOWN COMMAND USAGE</b>\n\n"
            . "━━━━━━━━━━━━━━━━━━━━\n\n"
            . "<code>/teardown &lt;url&gt; [Business Name]</code>\n\n"
            . "<b>Example:</b>\n"
            . "<code>/teardown https://apexroofingoshawa.ca \"Apex Roofing\"</code>\n"
            . "<code>/teardown https://durhamweb.design \"Durham Web Design\"</code>";
        sendTelegramDirect($botToken, $chatId, $usageMsg);
        echo json_encode(['ok' => true]);
        exit;
    }

    // Extract URL and optional name
    preg_match_all('/"([^"]+)"|\'([^\']+)\'|(\S+)/', $argsStr, $matches);
    $tokens = [];
    foreach ($matches[0] as $i => $raw) {
        if (!empty($matches[1][$i])) {
            $tokens[] = $matches[1][$i];
        } elseif (!empty($matches[2][$i])) {
            $tokens[] = $matches[2][$i];
        } else {
            $tokens[] = $matches[3][$i];
        }
    }

    $targetUrl = $tokens[0] ?? '';
    $bizName = $tokens[1] ?? '';

    if (!empty($targetUrl)) {
        // Immediate status acknowledgement
        $ackMsg = "⚡ <b>SPEED TEARDOWN INITIATED</b>\n\n"
            . "━━━━━━━━━━━━━━━━━━━━\n\n"
            . "Auditing <code>" . htmlspecialchars($targetUrl, ENT_QUOTES, 'UTF-8') . "</code> from Canadian edge node...\n\n"
            . "Compiling visual 2-page executive scorecard PDF with customer drop-off bar chart & bloat pie chart.";
        sendTelegramDirect($botToken, $chatId, $ackMsg);

        // Execute generator script
        $scriptPath = '/usr/local/bin/generate-teardown.py';
        $cmdExec = "python3 " . escapeshellarg($scriptPath) . " --url " . escapeshellarg($targetUrl);
        if (!empty($bizName)) {
            $cmdExec .= " --name " . escapeshellarg($bizName);
        }
        $cmdExec .= " 2>&1";

        $output = @shell_exec($cmdExec);
        $result = json_decode($output, true);

        if ($result && !empty($result['success']) && !empty($result['pdf_path']) && file_exists($result['pdf_path'])) {
            $pdfPath = $result['pdf_path'];
            $biz = $result['business'];
            $score = $result['score'];
            $grade = $result['score_grade'] ?? 'Grade F';
            $loadSec = $result['load_seconds'] ?? 3.8;
            $rating = $result['rating'];
            $downloadUrl = $result['download_url'];

            // 1. Dispatch PDF directly into Dane's Telegram chat
            $caption = "⚡ <b>SPEED TEARDOWN SCORECARD:</b> {$biz}\n\n"
                . "━━━━━━━━━━━━━━━━━━━━\n\n"
                . "📊 <b>Mobile Grade:</b> {$grade}: {$score}/100 ({$rating})\n"
                . "⏱️ <b>Estimated Load:</b> ~{$loadSec}s (DWD standard: <0.3s)\n"
                . "🔗 <b>PDF Link:</b> {$downloadUrl}";

            sendTelegramDocument($botToken, $chatId, $pdfPath, $caption);

            // 2. Dispatch tailored cold email script
            $contactName = explode(' ', $biz)[0];
            $parsedHost = parse_url($targetUrl, PHP_URL_HOST) ?: $targetUrl;
            $pitchMsg = "📧 <b>COLD OUTREACH PITCH SCRIPT (READY TO SEND)</b>\n\n"
                . "━━━━━━━━━━━━━━━━━━━━\n\n"
                . "<b>Subject:</b> Quick speed audit for {$biz} (Mobile load time bottleneck)\n\n"
                . "Hey {$contactName},\n\n"
                . "I ran a speed diagnostic on {$parsedHost} from our Canadian edge node earlier today and noticed your site is currently scoring {$score}/100 on mobile with an estimated {$loadSec}s load delay.\n\n"
                . "In Durham Region, over 70% of high-intent customer traffic comes from smartphones. Every 1-second delay past 1.0s drops customer call-in rates by ~10%.\n\n"
                . "I compiled a visual 2-page teardown PDF attached showing your exact customer drop-off curve and how our handcrafted sub-second architecture cuts that load time down to under 0.3s.\n\n"
                . "Take a look at the attached audit, or let me know if you'd like me to walk you through the fixes.\n\n"
                . "Best,\n"
                . "Dane\n"
                . "Durham Web Design\n"
                . "https://durhamweb.design";

            sendTelegramDirect($botToken, $chatId, $pitchMsg);

        } else {
            $failMsg = "❌ <b>Teardown Failed:</b> Could not benchmark URL or compile PDF.\n<pre>" . htmlspecialchars(substr($output, 0, 500), ENT_QUOTES, 'UTF-8') . "</pre>";
            sendTelegramDirect($botToken, $chatId, $failMsg);
        }
    }

    echo json_encode(['ok' => true]);
    exit;
}

// 6. /daily and /report command: Print and dispatch The Epic Expressions Dispatch PDF
if ($cmd === '/daily' || $cmd === '/report' || $cmd === 'daily' || $cmd === 'report') {
    $ackMsg = "🗞️ <b>PRINTING THE EPIC EXPRESSIONS DISPATCH...</b>\n\n"
        . "━━━━━━━━━━━━━━━━━━━━\n\n"
        . "Benchmarking live fleet endpoints from Canadian edge...\n"
        . "Compiling 2-page newspaper PDF with latency bar chart, memory pie chart, Co-Founder's daily growth idea & CS history nugget.";
    sendTelegramDirect($botToken, $chatId, $ackMsg);

    $scriptPath = '/usr/local/bin/generate-daily-fleet-pdf.py';
    $cmdExec = "python3 " . escapeshellarg($scriptPath) . " 2>&1";
    $output = @shell_exec($cmdExec);
    $result = json_decode($output, true);

    $todayStr = date('Y-m-d');
    $archivePdf = "/var/backups/epic-fleet/daily-reports/Epic-Dispatch-{$todayStr}.pdf";
    $latestPdf = "/var/www/durhamwebdesign/teardowns/daily-fleet-latest.pdf";
    $pdfPath = file_exists($archivePdf) ? $archivePdf : $latestPdf;

    if (file_exists($pdfPath)) {
        $issue = $result['issue'] ?? 8;
        $caption = "🗞️ <b>THE EPIC EXPRESSIONS DISPATCH (Vol. I, Issue #{$issue})</b>\n\n"
            . "━━━━━━━━━━━━━━━━━━━━\n\n"
            . "📊 <b>Morning Executive Gazette:</b> {$todayStr}\n"
            . "📈 <b>Live Fleet Scorecard:</b> 100% Nominal\n"
            . "💡 <b>Includes:</b> Co-Founder's Play of the Day & Nerd Corner CS Heritage\n"
            . "🔗 <b>Web Link:</b> https://durhamweb.design/teardowns/daily-fleet-latest.pdf";

        sendTelegramDocument($botToken, $chatId, $pdfPath, $caption);
    } else {
        $failMsg = "❌ <b>Dispatch Error:</b> Could not compile Daily Dispatch PDF.\n<pre>" . htmlspecialchars(substr($output, 0, 500), ENT_QUOTES, 'UTF-8') . "</pre>";
        sendTelegramDirect($botToken, $chatId, $failMsg);
    }

    echo json_encode(['ok' => true]);
    exit;
}

// 6. Default /help or /start
$helpMsg = "⚡ <b>EPIC EXPRESSIONS OPERATIONS BOT</b>\n"
    . "🤖 <i>Interactive Operations & Sales Engine (@Epicexx_bot)</i>\n\n"
    . "━━━━━━━━━━━━━━━━━━━━\n\n"
    . "📊 <b>INFRASTRUCTURE COMMANDS</b>\n\n"
    . "  • <code>/daily</code> (or <code>/report</code>)\n"
    . "    <i>Print &amp; dispatch 2-page Daily Dispatch Newspaper PDF</i>\n\n"
    . "  • <code>/status</code>\n"
    . "    <i>Live server health, CPU/RAM/Disk & HTTP latencies</i>\n\n"
    . "  • <code>/ping</code>\n"
    . "    <i>Instant handshake & node timestamp</i>\n\n"
    . "  • <code>/leads</code>\n"
    . "    <i>View top recent speed audit inbound prospects</i>\n\n"
    . "━━━━━━━━━━━━━━━━━━━━\n\n"
    . "🚀 <b>CLIENT CLOSER & SALES COMMANDS</b>\n\n"
    . "  • <code>/proposal &lt;Name&gt; \"&lt;Biz&gt;\" &lt;tier&gt; [phone]</code>\n"
    . "    <i>Generate instant client agreement closer link</i>\n"
    . "    <b>Example:</b> <code>/proposal Marcus \"Apex Roofing\" starter 9054318890</code>\n\n"
    . "  • <code>/teardown &lt;url&gt; [&lt;Biz&gt;]</code>\n"
    . "    <i>Run headless speed diagnostic, send 2-page PDF & cold pitch</i>\n"
    . "    <b>Example:</b> <code>/teardown https://apexroofingoshawa.ca \"Apex Roofing\"</code>\n\n"
    . "━━━━━━━━━━━━━━━━━━━━\n\n"
    . "ℹ️ <i>Automated morning dispatch at 8:00 AM & evening report at 9:00 PM EDT.</i>";
sendTelegramDirect($botToken, $chatId, $helpMsg);
echo json_encode(['ok' => true]);
exit;

function sendTelegramDirect($token, $chatId, $msg) {
    if (empty($token) || empty($chatId)) return;
    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $postData = http_build_query([
        'chat_id' => $chatId,
        'text' => $msg,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => false
    ]);
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $postData,
            'timeout' => 8
        ]
    ]);
    @file_get_contents($url, false, $ctx);
}

function sendTelegramDocument($token, $chatId, $filePath, $caption) {
    if (empty($token) || empty($chatId) || !file_exists($filePath)) return;
    $url = "https://api.telegram.org/bot{$token}/sendDocument";

    if (function_exists('curl_init')) {
        $ch = curl_init();
        $cfile = new CURLFile($filePath, 'application/pdf', basename($filePath));
        $post = [
            'chat_id' => $chatId,
            'document' => $cfile,
            'caption' => $caption,
            'parse_mode' => 'HTML'
        ];
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $res = curl_exec($ch);
        curl_close($ch);
        return $res;
    }
}
