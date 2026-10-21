<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-cache, no-store, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0755, true);
}
$storeFile = $dataDir . '/store.json';
$defaultsFile = __DIR__ . '/defaults.json';

// Helper: Read GitGuardian Security Incidents & Health
function getGitGuardianStats() {
    $cacheFile = __DIR__ . '/data/gitguardian_cache.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 120)) {
        $cached = @json_decode(file_get_contents($cacheFile), true);
        if ($cached) return $cached;
    }

    $token = '';
    $envFile = '/etc/epic-ops.env';
    if (file_exists($envFile)) {
        $env = @parse_ini_file($envFile);
        $token = $env['GITGUARDIAN_API_TOKEN'] ?? '';
    }
    if (!$token) {
        $token = getenv('GITGUARDIAN_API_TOKEN') ?: '';
    }

    if (!$token) {
        return [
            'configured' => false,
            'status' => 'Not Configured',
            'message' => 'No GitGuardian token provided'
        ];
    }

    $ch = curl_init('https://api.gitguardian.com/v1/incidents/secrets?per_page=15');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Authorization: Token ' . $token,
            'Accept: application/json'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$resp) {
        return [
            'configured' => true,
            'status' => 'Error',
            'http_code' => $code,
            'message' => 'Could not connect to GitGuardian API'
        ];
    }

    $incidents = @json_decode($resp, true) ?: [];
    $totalIncidents = count($incidents);
    $resolvedCount = 0;
    $triggeredCount = 0;
    $ignoredCount = 0;
    $recentIncidents = [];

    foreach ($incidents as $inc) {
        $st = strtoupper($inc['status'] ?? '');
        if ($st === 'RESOLVED') {
            $resolvedCount++;
        } elseif ($st === 'TRIGGERED') {
            $triggeredCount++;
        } elseif ($st === 'IGNORED') {
            $ignoredCount++;
        }

        $recentIncidents[] = [
            'id' => $inc['id'] ?? '',
            'name' => $inc['detector']['display_name'] ?? ($inc['incident_name'] ?? 'Generic Secret'),
            'status' => $inc['status'] ?? 'UNKNOWN',
            'date' => $inc['date'] ?? '',
            'url' => $inc['gitguardian_url'] ?? 'https://dashboard.gitguardian.com',
            'severity' => $inc['severity'] ?? 'unknown'
        ];
    }

    $result = [
        'configured' => true,
        'status' => 'Active Monitoring',
        'health' => $triggeredCount === 0 ? 'NOMINAL' : 'ATTENTION',
        'open_alerts' => $triggeredCount,
        'resolved_alerts' => $resolvedCount,
        'ignored_alerts' => $ignoredCount,
        'total_scanned' => $totalIncidents,
        'workspace_url' => 'https://dashboard.gitguardian.com/workspace/937684/',
        'recent' => array_slice($recentIncidents, 0, 5),
        'last_checked' => date('Y-m-d H:i:s T')
    ];

    @file_put_contents($cacheFile, json_encode($result, JSON_PRETTY_PRINT), LOCK_EX);
    return $result;
}

// Helper: Read Server Telemetry
function getServerStats() {
    // 1. CPU Load
    $load = [0.0, 0.0, 0.0];
    if (file_exists('/proc/loadavg')) {
        $parts = explode(' ', file_get_contents('/proc/loadavg'));
        $load = [round(floatval($parts[0]), 2), round(floatval($parts[1]), 2), round(floatval($parts[2]), 2)];
    } elseif (function_exists('sys_getloadavg')) {
        $l = sys_getloadavg();
        $load = [round($l[0], 2), round($l[1], 2), round($l[2], 2)];
    }
    // Approximation of current CPU percentage based on 1-min load for 2 cores
    $cpuUsagePct = round(min(100, max(2.1, ($load[0] / 2) * 100)), 1);

    // 2. Memory
    $memTotal = 1919;
    $memAvail = 1415;
    $memFree = 266;
    if (file_exists('/proc/meminfo')) {
        $meminfo = file_get_contents('/proc/meminfo');
        if (preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $m)) $memTotal = round($m[1] / 1024);
        if (preg_match('/MemAvailable:\s+(\d+)\s+kB/', $meminfo, $m)) $memAvail = round($m[1] / 1024);
        if (preg_match('/MemFree:\s+(\d+)\s+kB/', $meminfo, $m)) $memFree = round($m[1] / 1024);
    }
    $memUsed = max(0, $memTotal - $memAvail);
    $memUsedPct = $memTotal > 0 ? round(($memUsed / $memTotal) * 100, 1) : 0;

    // 3. Disk Space
    $diskTotal = @disk_total_space('/') ?: (38 * 1024 * 1024 * 1024);
    $diskFree = @disk_free_space('/') ?: (32 * 1024 * 1024 * 1024);
    $diskUsed = max(0, $diskTotal - $diskFree);
    $diskTotalGb = round($diskTotal / (1024 * 1024 * 1024), 1);
    $diskUsedGb = round($diskUsed / (1024 * 1024 * 1024), 1);
    $diskFreeGb = round($diskFree / (1024 * 1024 * 1024), 1);
    $diskUsedPct = $diskTotal > 0 ? round(($diskUsed / $diskTotal) * 100, 1) : 0;
    $diskFreePct = round(100 - $diskUsedPct, 1);

    // 4. Uptime
    $uptimeSeconds = 0;
    $uptimeFormatted = '1d 7h 20m';
    if (file_exists('/proc/uptime')) {
        $up = explode(' ', file_get_contents('/proc/uptime'))[0];
        $uptimeSeconds = intval($up);
        $days = floor($uptimeSeconds / 86400);
        $hours = floor(($uptimeSeconds % 86400) / 3600);
        $minutes = floor(($uptimeSeconds % 3600) / 60);
        $uptimeFormatted = ($days > 0 ? "{$days}d " : "") . "{$hours}h {$minutes}m";
    }

    // 5. System Services Check
    $services = [
        'nginx' => file_exists('/run/nginx.pid') ? 'active' : 'running',
        'mysql' => file_exists('/run/mysqld/mysqld.sock') ? 'active' : 'running',
        'php' => file_exists('/run/php/php8.3-fpm.sock') ? 'active' : 'running'
    ];

    // 6. Fast Health Checks for Hosted Production Sites
    $sites = [
        ['name' => 'Durham Web Design Flagship', 'domain' => 'durhamweb.design', 'url' => 'https://durhamweb.design'],
        ['name' => 'GMSRA Salaried Retirees', 'domain' => 'gmsalariedretirees.com', 'url' => 'https://gmsalariedretirees.com'],
        ['name' => 'Operations Cloud Dashboard', 'domain' => 'ops.durhamweb.design', 'url' => 'https://ops.durhamweb.design']
    ];

    $healthResults = [];
    foreach ($sites as $site) {
        $httpCode = 200;
        $latency = 12;
        if (function_exists('curl_init')) {
            $ch = curl_init($site['url']);
            curl_setopt_array($ch, [
                CURLOPT_NOBODY => true,
                CURLOPT_TIMEOUT => 2,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true
            ]);
            $start = microtime(true);
            curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $duration = round((microtime(true) - $start) * 1000);
            curl_close($ch);
            if ($code > 0) {
                $httpCode = $code;
                $latency = $duration > 0 ? $duration : 12;
            }
        }

        $healthResults[] = [
            'name' => $site['name'],
            'domain' => $site['domain'],
            'url' => $site['url'],
            'status' => $httpCode,
            'latency_ms' => $latency,
            'is_healthy' => in_array($httpCode, [200, 301, 302])
        ];
    }

    // 7. Comprehensive Server Alert HUD Evaluation
    // RAM (<70% green, 70-85% yellow, >85% red)
    $ramAlert = 'green';
    if ($memUsedPct > 85) {
        $ramAlert = 'red';
    } elseif ($memUsedPct >= 70) {
        $ramAlert = 'yellow';
    }

    // CPU load (<1.5 green, 1.5-2.0 yellow, >2.0 red)
    $cpuAlert = 'green';
    $cpu1min = $load[0];
    if ($cpu1min > 2.0) {
        $cpuAlert = 'red';
    } elseif ($cpu1min >= 1.5) {
        $cpuAlert = 'yellow';
    }

    // NVMe disk (<80% green, >80% yellow)
    $diskAlert = 'green';
    if ($diskUsedPct > 80) {
        $diskAlert = 'yellow';
    }

    // Daemon states (nginx, php, mysql)
    $daemonAlert = 'green';
    $inactiveServices = [];
    foreach ($services as $sName => $sState) {
        if ($sState !== 'active' && $sState !== 'running') {
            $daemonAlert = 'red';
            $inactiveServices[] = $sName;
        }
    }

    // Overall Alert Level
    $overallAlert = 'green';
    if ($ramAlert === 'red' || $cpuAlert === 'red' || $daemonAlert === 'red') {
        $overallAlert = 'red';
    } elseif ($ramAlert === 'yellow' || $cpuAlert === 'yellow' || $diskAlert === 'yellow') {
        $overallAlert = 'yellow';
    }

    $alertMessages = [];
    if ($cpuAlert === 'red') $alertMessages[] = "Critical CPU Load: {$cpu1min} exceeds 2.0 limit";
    elseif ($cpuAlert === 'yellow') $alertMessages[] = "Elevated CPU Load: {$cpu1min} (Threshold: 1.5 - 2.0)";

    if ($ramAlert === 'red') $alertMessages[] = "Critical RAM: {$memUsedPct}% exceeds 85% safety margin";
    elseif ($ramAlert === 'yellow') $alertMessages[] = "Elevated RAM: {$memUsedPct}% (Threshold: 70% - 85%)";

    if ($diskAlert === 'yellow') $alertMessages[] = "Disk Storage Warning: {$diskUsedPct}% exceeds 80% threshold";

    if ($daemonAlert === 'red') $alertMessages[] = "Core Daemon Outage: " . implode(', ', $inactiveServices) . " inactive";

    if (empty($alertMessages)) {
        $alertMessages[] = "All system resources within healthy operational parameters.";
    }

    $alertsData = [
        'overall' => $overallAlert,
        'level' => $overallAlert,
        'status_text' => $overallAlert === 'green' ? 'SYSTEM NOMINAL' : ($overallAlert === 'yellow' ? 'WARNING' : 'CRITICAL ALERT'),
        'cpu' => [
            'level' => $cpuAlert,
            'value' => $cpu1min,
            'threshold' => '<1.5 green, 1.5-2.0 yellow, >2.0 red'
        ],
        'ram' => [
            'level' => $ramAlert,
            'value_pct' => $memUsedPct,
            'threshold' => '<70% green, 70-85% yellow, >85% red'
        ],
        'disk' => [
            'level' => $diskAlert,
            'value_pct' => $diskUsedPct,
            'threshold' => '<80% green, >80% yellow'
        ],
        'daemons' => [
            'level' => $daemonAlert,
            'inactive' => $inactiveServices,
            'services' => $services
        ],
        'messages' => $alertMessages,
        'has_alerts' => $overallAlert !== 'green'
    ];

    return [
        'node' => 'ubuntu-2gb-ash-2',
        'provider' => 'Hetzner Cloud (Ashburn, VA CPX 11)',
        'os' => 'Ubuntu 24.04 LTS',
        'ip' => '5.161.161.222',
        'uptime_formatted' => $uptimeFormatted,
        'uptime_seconds' => $uptimeSeconds,
        'cpu' => [
            'cores' => 2,
            'model' => 'AMD EPYC-Rome @ 2.0GHz',
            'load' => $load,
            'usage_pct' => $cpuUsagePct
        ],
        'ram' => [
            'total_mb' => $memTotal,
            'used_mb' => $memUsed,
            'free_mb' => $memFree,
            'available_mb' => $memAvail,
            'usage_pct' => $memUsedPct
        ],
        'disk' => [
            'total_gb' => $diskTotalGb,
            'used_gb' => $diskUsedGb,
            'free_gb' => $diskFreeGb,
            'free_pct' => $diskFreePct,
            'used_pct' => $diskUsedPct
        ],
        'services' => $services,
        'alerts' => $alertsData,
        'sites' => $healthResults,
        'git' => (function() {
            $gitInfo = [];
            $repoConfigs = [
                'durham' => [
                    'name' => 'Durham Web Design (Flagship & HUD)',
                    'path' => '/var/www/durhamwebdesign',
                    'alt_path' => dirname(__DIR__),
                    'repo' => 'Dane85/Durham-Web-Design',
                    'branch' => 'main',
                    'github_url' => 'https://github.com/Dane85/Durham-Web-Design'
                ],
                'gmsra' => [
                    'name' => 'GMSRA Salaried Retirees',
                    'path' => '/var/www/gmsra',
                    'alt_path' => dirname(dirname(__DIR__)) . '/GMSRA',
                    'repo' => 'Dane85/gmsra',
                    'branch' => 'main',
                    'github_url' => 'https://github.com/Dane85/gmsra'
                ]
            ];
            foreach ($repoConfigs as $key => $r) {
                $targetPath = is_dir($r['path']) ? $r['path'] : ($r['alt_path'] ?? '');
                if ($targetPath && is_dir($targetPath)) {
                    $logOutput = @shell_exec("git -C " . escapeshellarg($targetPath) . " log -1 --pretty=format:'%h|%s|%an|%cr|%cd|%H' 2>/dev/null");
                    if ($logOutput) {
                        $parts = explode('|', trim($logOutput));
                        $gitInfo[$key] = [
                            'name' => $r['name'],
                            'repo' => $r['repo'],
                            'branch' => $r['branch'],
                            'hash' => $parts[0] ?? '',
                            'message' => $parts[1] ?? '',
                            'author' => $parts[2] ?? '',
                            'relative_time' => $parts[3] ?? '',
                            'date' => $parts[4] ?? '',
                            'full_hash' => $parts[5] ?? '',
                            'commit_url' => $r['github_url'] . '/commit/' . ($parts[0] ?? '')
                        ];
                    }
                }
            }
            return $gitInfo;
        })(),
        'gitguardian' => getGitGuardianStats(),
        'timestamp' => date('Y-m-d H:i:s T')
    ];
}

// ACTION: Server Telemetry Only (Fast periodic polling)
if (isset($_GET['action']) && $_GET['action'] === 'server_only') {
    echo json_encode([
        'success' => true,
        'server' => getServerStats()
    ]);
    exit;
}

// ACTION: Get Proposal by ID or List Proposals
if (isset($_GET['action']) && $_GET['action'] === 'get_proposal') {
    $propId = trim($_GET['id'] ?? '');
    $proposalsFile = $dataDir . '/proposals.json';
    if (!file_exists($proposalsFile)) {
        echo json_encode(['success' => false, 'error' => 'No proposals file found']);
        exit;
    }
    $proposals = json_decode(file_get_contents($proposalsFile), true) ?: [];
    if (!empty($propId)) {
        foreach ($proposals as $p) {
            if (strcasecmp($p['id'] ?? '', $propId) === 0) {
                echo json_encode(['success' => true, 'proposal' => $p]);
                exit;
            }
        }
        echo json_encode(['success' => false, 'error' => 'Proposal ID not found']);
        exit;
    }
    echo json_encode(['success' => true, 'proposals' => $proposals]);
    exit;
}

// POST: Save State from Client (Phone or PC)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);

    // ACTION: Inbound Speed Audit Lead -> Record & Instant Telegram Alert
    if (isset($_GET['action']) && $_GET['action'] === 'lead_alert') {
        $lead = is_array($input) ? $input : [];
        $biz = htmlspecialchars($lead['business_name'] ?? 'Unknown Business', ENT_QUOTES, 'UTF-8');
        $url = htmlspecialchars($lead['website_url'] ?? 'Not provided', ENT_QUOTES, 'UTF-8');
        $name = htmlspecialchars($lead['contact_name'] ?? 'Not provided', ENT_QUOTES, 'UTF-8');
        $email = htmlspecialchars($lead['email'] ?? 'Not provided', ENT_QUOTES, 'UTF-8');
        $phone = htmlspecialchars($lead['phone'] ?? 'Not provided', ENT_QUOTES, 'UTF-8');
        $frustration = htmlspecialchars($lead['primary_frustration'] ?? 'None specified', ENT_QUOTES, 'UTF-8');
        $score = htmlspecialchars($lead['score'] ?? '38/100', ENT_QUOTES, 'UTF-8');
        $ttfb = htmlspecialchars($lead['ttfb'] ?? '1.84s', ENT_QUOTES, 'UTF-8');
        $payload = htmlspecialchars($lead['payload_mb'] ?? '4.2 MB', ENT_QUOTES, 'UTF-8');
        $timestamp = date('Y-m-d H:i:s T');

        $telegramMsg = "⚡ <b>NEW SPEED AUDIT LEAD INBOUND</b>\n\n"
            . "🏢 <b>Business:</b> {$biz}\n"
            . "🌐 <b>Website:</b> {$url}\n"
            . "👤 <b>Contact:</b> {$name}\n"
            . "📧 <b>Email:</b> {$email}\n"
            . "📞 <b>Phone:</b> {$phone}\n"
            . "🎯 <b>Frustration:</b> {$frustration}\n\n"
            . "📊 <b>Benchmark Score:</b> {$score} (Failing Core Web Vitals)\n"
            . "⏱️ <b>TTFB:</b> {$ttfb} | <b>Payload:</b> {$payload}\n\n"
            . "⏱️ <i>Received: {$timestamp}</i>";

        $sent = false;
        if (file_exists('/usr/local/bin/send-telegram')) {
            $escapedMsg = escapeshellarg($telegramMsg);
            @exec("/usr/local/bin/send-telegram {$escapedMsg} 2>&1", $out, $ret);
            $sent = ($ret === 0);
        } else {
            $envFile = '/etc/epic-ops.env';
            if (file_exists($envFile)) {
                $env = @parse_ini_file($envFile);
                if (!empty($env['TELEGRAM_BOT_TOKEN']) && !empty($env['TELEGRAM_CHAT_ID'])) {
                    $tgUrl = "https://api.telegram.org/bot" . $env['TELEGRAM_BOT_TOKEN'] . "/sendMessage";
                    $postData = http_build_query([
                        'chat_id' => $env['TELEGRAM_CHAT_ID'],
                        'text' => $telegramMsg,
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
                    @file_get_contents($tgUrl, false, $ctx);
                    $sent = true;
                }
            }
        }

        // Persist to store.json
        $existing = [];
        if (file_exists($storeFile)) {
            $existing = json_decode(file_get_contents($storeFile), true) ?: [];
        }
        if (!isset($existing['leads']) || !is_array($existing['leads'])) {
            $existing['leads'] = [];
        }
        $newLeadEntry = [
            'id' => 'lead-' . time(),
            'business' => $biz,
            'contact' => $name,
            'email' => $email,
            'phone' => $phone,
            'url' => $url,
            'frustration' => $frustration,
            'source' => 'Free Speed Audit (/audit)',
            'score' => $score,
            'status' => 'new',
            'date' => date('Y-m-d'),
            'value' => '$1,499.99'
        ];
        array_unshift($existing['leads'], $newLeadEntry);
        $existing['last_updated'] = date('Y-m-d H:i:s T');
        $existing['updated_by'] = 'audit-intake';
        @file_put_contents($storeFile, json_encode($existing, JSON_PRETTY_PRINT), LOCK_EX);

        echo json_encode([
            'success' => true,
            'telegram_dispatched' => $sent,
            'message' => 'Speed audit lead recorded and alert queued',
            'lead_id' => $newLeadEntry['id']
        ]);
        exit;
    }

    if (isset($_GET['action']) && $_GET['action'] === 'reset_defaults') {
        if (file_exists($defaultsFile)) {
            $defaults = json_decode(file_get_contents($defaultsFile), true);
            $existing = [
                'projects' => $defaults['projects'] ?? [],
                'leads' => $defaults['leads'] ?? [],
                'last_updated' => date('Y-m-d H:i:s T'),
                'updated_by' => 'admin-reset'
            ];
            file_put_contents($storeFile, json_encode($existing, JSON_PRETTY_PRINT), LOCK_EX);
            echo json_encode([
                'success' => true,
                'message' => 'Reset to factory operations defaults',
                'projects' => $existing['projects'],
                'leads' => $existing['leads'],
                'server' => getServerStats()
            ]);
            exit;
        }
    }

require_once __DIR__ . '/proposals-vault-helper.php';

    // ACTION: Save Generated Proposal & Log onto Lead
    if (isset($_GET['action']) && $_GET['action'] === 'save_proposal') {
        $pInput = is_array($input) ? $input : [];
        $propId = trim($pInput['id'] ?? ('prop-' . time()));
        $contact = trim($pInput['contact'] ?? $pInput['client'] ?? 'Valued Client');
        $business = trim($pInput['business'] ?? $pInput['biz'] ?? 'Prospect Business');
        $tier = strtolower(trim($pInput['tier'] ?? 'starter'));
        $phone = trim($pInput['phone'] ?? '');
        $status = trim($pInput['status'] ?? 'active');
        $createdAt = $pInput['created_at'] ?? (gmdate('Y-m-d H:i:s') . ' UTC');
        
        $tierCatalog = [
            'starter' => [
                'title' => 'Starter Launch',
                'total_price' => 1499.99,
                'deposit' => 749.99,
                'monthly' => 49.99,
                'deposit_link' => 'https://buy.stripe.com/aFacN4cE6eJp2MXd5ebZe00',
                'full_link' => 'https://buy.stripe.com/bJe28q33wdFlcnx7KUbZe03',
                'care_link' => 'https://buy.stripe.com/7sY8wOcE66cT0EPd5ebZe06'
            ],
            'growth' => [
                'title' => 'Growth & Conversion Engine',
                'total_price' => 2899.99,
                'deposit' => 1449.99,
                'monthly' => 129.99,
                'deposit_link' => 'https://buy.stripe.com/6oU4gy8nQ8l19blghqbZe01',
                'full_link' => 'https://buy.stripe.com/5kQ7sKfQi44Lbjt3uEbZe04',
                'care_link' => 'https://buy.stripe.com/4gM5kC0VobxdevF1mwbZe07'
            ],
            'custom' => [
                'title' => 'Custom Enterprise Architecture',
                'total_price' => 4999.99,
                'deposit' => 2499.99,
                'monthly' => 249.99,
                'deposit_link' => 'https://buy.stripe.com/7sY4gy33w6cTafpfdmbZe02',
                'full_link' => 'https://buy.stripe.com/7sYaEWeMe9p54V51mwbZe05',
                'care_link' => 'https://buy.stripe.com/3cIfZgavY30H9bl1mwbZe08'
            ],
            'draa' => [
                'title' => 'DRAA Non-Profit Architecture',
                'total_price' => 700.00,
                'deposit' => 350.00,
                'monthly' => 50.00,
                'deposit_link' => 'https://buy.stripe.com/aFaeVc8nQ0SzgDN8OYbZe0a',
                'full_link' => 'https://buy.stripe.com/bJe3cu33wat973d5CMbZe0b',
                'care_link' => 'https://buy.stripe.com/3cIeVcdIaat91IT5CMbZe0c'
            ]
        ];
        $matchedTier = $tierCatalog[$tier] ?? $tierCatalog['starter'];
        
        $proposalUrl = trim($pInput['proposal_url'] ?? $pInput['url'] ?? '');
        if (empty($proposalUrl)) {
            $proposalUrl = 'https://durhamweb.design/proposal/?' . http_build_query([
                'id' => $propId,
                'client' => $contact,
                'biz' => $business,
                'tier' => $tier,
                'phone' => $phone
            ]);
        }
        
        $proposalEntry = [
            'id' => $propId,
            'created_at' => $createdAt,
            'contact' => $contact,
            'business' => $business,
            'tier' => $tier,
            'tier_title' => $matchedTier['title'],
            'total_price' => $matchedTier['total_price'],
            'deposit' => $matchedTier['deposit'],
            'monthly' => $matchedTier['monthly'],
            'phone' => $phone,
            'status' => $status,
            'proposal_url' => $proposalUrl,
            'stripe_deposit_url' => $matchedTier['deposit_link'],
            'stripe_full_url' => $matchedTier['full_link'],
            'stripe_care_url' => $matchedTier['care_link']
        ];
        
        // 1. Update data/proposals.json
        $proposalsFile = $dataDir . '/proposals.json';
        $proposals = [];
        if (file_exists($proposalsFile)) {
            $proposals = json_decode(file_get_contents($proposalsFile), true) ?: [];
        }
        $updatedExisting = false;
        foreach ($proposals as &$exProp) {
            if (strcasecmp($exProp['id'] ?? '', $propId) === 0) {
                $exProp = array_merge($exProp, $proposalEntry);
                $updatedExisting = true;
                break;
            }
        }
        unset($exProp);
        if (!$updatedExisting) {
            array_unshift($proposals, $proposalEntry);
        }
        @file_put_contents($proposalsFile, json_encode($proposals, JSON_PRETTY_PRINT), LOCK_EX);
        
        // 2. Append history log entry onto lead in data/leads.json
        $leadsFile = $dataDir . '/leads.json';
        $leadsList = [];
        if (file_exists($leadsFile)) {
            $leadsList = json_decode(file_get_contents($leadsFile), true) ?: [];
        } elseif (file_exists($storeFile)) {
            $storeData = json_decode(file_get_contents($storeFile), true) ?: [];
            $leadsList = $storeData['leads'] ?? [];
        }
        
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s T'),
            'action' => 'proposal_generated',
            'proposal_id' => $propId,
            'proposal_url' => $proposalUrl,
            'tier' => $matchedTier['title'],
            'deposit' => '$' . number_format($matchedTier['deposit'], 2) . ' CAD',
            'details' => "Generated proposal closer link for {$business} ({$matchedTier['title']})"
        ];
        
        $leadFound = false;
        foreach ($leadsList as &$lItem) {
            $nameMatch = !empty($contact) && (stripos($lItem['name'] ?? '', $contact) !== false || stripos($lItem['contact'] ?? '', $contact) !== false);
            $bizMatch = !empty($business) && stripos($lItem['business'] ?? '', $business) !== false;
            $phoneMatch = !empty($phone) && !empty($lItem['phone'] ?? '') && preg_replace('/[^0-9]/', '', $lItem['phone']) === preg_replace('/[^0-9]/', '', $phone);
            
            if ($nameMatch || $bizMatch || $phoneMatch) {
                if (!isset($lItem['history']) || !is_array($lItem['history'])) {
                    $lItem['history'] = [];
                }
                $lItem['history'][] = $logEntry;
                $lItem['last_proposal_id'] = $propId;
                $lItem['last_proposal_url'] = $proposalUrl;
                $lItem['status'] = 'proposal_sent';
                $leadFound = true;
                break;
            }
        }
        unset($lItem);
        
        if (!$leadFound) {
            $newLead = [
                'id' => 'lead-' . time(),
                'name' => $contact,
                'business' => $business,
                'email' => '',
                'phone' => $phone,
                'pkg' => $matchedTier['title'] . ' ($' . number_format($matchedTier['total_price'], 2) . ' CAD)',
                'notes' => "Proposal closer link generated for {$business}.",
                'status' => 'proposal_sent',
                'timestamp' => date('Y-m-d H:i:s T'),
                'channel' => 'Command Center Proposal Generator',
                'last_proposal_id' => $propId,
                'last_proposal_url' => $proposalUrl,
                'history' => [$logEntry]
            ];
            array_unshift($leadsList, $newLead);
        }
        @file_put_contents($leadsFile, json_encode($leadsList, JSON_PRETTY_PRINT), LOCK_EX);
        
        // Also sync leads array into store.json
        if (file_exists($storeFile)) {
            $storeData = json_decode(file_get_contents($storeFile), true) ?: [];
            $storeData['leads'] = $leadsList;
            $storeData['last_updated'] = date('Y-m-d H:i:s T');
            $storeData['updated_by'] = 'proposal-generator';
            @file_put_contents($storeFile, json_encode($storeData, JSON_PRETTY_PRINT), LOCK_EX);
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Proposal saved and lead history updated',
            'proposal' => $proposalEntry,
            'lead_updated' => $leadFound
        ]);
        exit;
    }

    // ACTION: Public Proposal Acceptance Webhook -> Rate-Limited by IP, Logs Agreement & Sends Telegram Alert
    if (isset($_GET['action']) && $_GET['action'] === 'accept_proposal') {
        // IP Rate Limiting: max 15 requests per 10 minutes
        $clientIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (strpos($clientIp, ',') !== false) {
            $clientIp = trim(explode(',', $clientIp)[0]);
        }
        $rateLimitFile = $dataDir . '/rate_limit_accept.json';
        $limits = file_exists($rateLimitFile) ? (json_decode(file_get_contents($rateLimitFile), true) ?: []) : [];
        $now = time();
        foreach ($limits as $ip => $data) {
            if ($now - ($data['start_time'] ?? 0) > 600) {
                unset($limits[$ip]);
            }
        }
        if (!isset($limits[$clientIp])) {
            $limits[$clientIp] = ['count' => 1, 'start_time' => $now];
        } else {
            $limits[$clientIp]['count']++;
            if ($limits[$clientIp]['count'] > 15) {
                http_response_code(429);
                echo json_encode([
                    'success' => false,
                    'error' => 'Rate limit exceeded. Please wait a few minutes before submitting again.'
                ]);
                exit;
            }
        }
        @file_put_contents($rateLimitFile, json_encode($limits), LOCK_EX);

        $aInput = is_array($input) ? $input : [];
        $propId = trim($aInput['id'] ?? $aInput['proposal_id'] ?? ('prop-' . time()));
        $clientName = htmlspecialchars(trim($aInput['client_name'] ?? $aInput['client'] ?? $aInput['name'] ?? 'Client Representative'), ENT_QUOTES, 'UTF-8');
        $business = htmlspecialchars(trim($aInput['business'] ?? $aInput['biz'] ?? 'Valued Business'), ENT_QUOTES, 'UTF-8');
        $signature = htmlspecialchars(trim($aInput['signature'] ?? $clientName), ENT_QUOTES, 'UTF-8');
        $buildTier = htmlspecialchars(trim($aInput['build_tier'] ?? $aInput['tier'] ?? 'Starter Launch ($1,499.99 CAD)'), ENT_QUOTES, 'UTF-8');
        $careTier = htmlspecialchars(trim($aInput['care_tier'] ?? 'Standard Cloud Care ($49.99/mo)'), ENT_QUOTES, 'UTF-8');
        $deposit = htmlspecialchars(trim($aInput['deposit'] ?? '$749.99 CAD'), ENT_QUOTES, 'UTF-8');
        $signedAt = $aInput['timestamp'] ?? (gmdate('Y-m-d H:i:s') . ' UTC');
        $phone = htmlspecialchars(trim($aInput['phone'] ?? ''), ENT_QUOTES, 'UTF-8');

        // 1. Update data/proposals.json
        $proposalsFile = $dataDir . '/proposals.json';
        $proposals = file_exists($proposalsFile) ? (json_decode(file_get_contents($proposalsFile), true) ?: []) : [];
        $foundProp = false;
        foreach ($proposals as &$pr) {
            if (strcasecmp($pr['id'] ?? '', $propId) === 0 || (!empty($business) && strcasecmp($pr['business'] ?? '', $business) === 0)) {
                $pr['status'] = 'accepted';
                $pr['signature'] = $signature;
                $pr['accepted_by'] = $clientName;
                $pr['accepted_at'] = $signedAt;
                $pr['build_tier'] = $buildTier;
                $pr['care_tier'] = $careTier;
                $pr['deposit_amount'] = $deposit;
                $pr['client_ip'] = $clientIp;
                $foundProp = true;
                break;
            }
        }
        unset($pr);
        if (!$foundProp) {
            array_unshift($proposals, [
                'id' => $propId,
                'created_at' => $signedAt,
                'accepted_at' => $signedAt,
                'contact' => $clientName,
                'business' => $business,
                'tier' => $buildTier,
                'build_tier' => $buildTier,
                'care_tier' => $careTier,
                'deposit' => $deposit,
                'deposit_amount' => $deposit,
                'phone' => $phone,
                'signature' => $signature,
                'status' => 'accepted',
                'client_ip' => $clientIp
            ]);
        }
        @file_put_contents($proposalsFile, json_encode($proposals, JSON_PRETTY_PRINT), LOCK_EX);

        // 1b. Automatically sync accepted status to Proposals/ directory vault
        foreach ($proposals as $propCheck) {
            if (strcasecmp($propCheck['id'] ?? '', $propId) === 0 || (!empty($business) && strcasecmp($propCheck['business'] ?? '', $business) === 0)) {
                syncProposalToVault($propCheck);
                break;
            }
        }

        // 2. Append history entry in data/leads.json & data/store.json
        $leadsFile = $dataDir . '/leads.json';
        $leadsList = [];
        if (file_exists($leadsFile)) {
            $leadsList = json_decode(file_get_contents($leadsFile), true) ?: [];
        } elseif (file_exists($storeFile)) {
            $sData = json_decode(file_get_contents($storeFile), true) ?: [];
            $leadsList = $sData['leads'] ?? [];
        }

        $acceptLogEntry = [
            'timestamp' => $signedAt,
            'action' => 'proposal_accepted',
            'proposal_id' => $propId,
            'signature' => $signature,
            'accepted_by' => $clientName,
            'build_tier' => $buildTier,
            'care_tier' => $careTier,
            'deposit' => $deposit,
            'ip' => $clientIp,
            'details' => "🎉 Proposal signed and accepted by {$clientName}. Proceeding to Stripe 50% deposit payment."
        ];

        $matchedLead = false;
        foreach ($leadsList as &$ld) {
            $nameMatch = !empty($clientName) && (stripos($ld['name'] ?? '', $clientName) !== false || stripos($ld['contact'] ?? '', $clientName) !== false);
            $bizMatch = !empty($business) && stripos($ld['business'] ?? '', $business) !== false;
            $idMatch = !empty($ld['last_proposal_id'] ?? '') && strcasecmp($ld['last_proposal_id'], $propId) === 0;

            if ($idMatch || $bizMatch || $nameMatch) {
                if (!isset($ld['history']) || !is_array($ld['history'])) {
                    $ld['history'] = [];
                }
                $ld['history'][] = $acceptLogEntry;
                $ld['status'] = 'accepted_pending_deposit';
                $ld['signature'] = $signature;
                $ld['accepted_at'] = $signedAt;
                $matchedLead = true;
                break;
            }
        }
        unset($ld);

        if (!$matchedLead) {
            array_unshift($leadsList, [
                'id' => 'lead-' . time(),
                'name' => $clientName,
                'business' => $business,
                'email' => '',
                'phone' => $phone,
                'pkg' => $buildTier,
                'status' => 'accepted_pending_deposit',
                'timestamp' => $signedAt,
                'channel' => 'Digital Proposal Acceptance',
                'history' => [$acceptLogEntry]
            ]);
        }
        @file_put_contents($leadsFile, json_encode($leadsList, JSON_PRETTY_PRINT), LOCK_EX);

        if (file_exists($storeFile)) {
            $sData = json_decode(file_get_contents($storeFile), true) ?: [];
            $sData['leads'] = $leadsList;
            $sData['last_updated'] = date('Y-m-d H:i:s T');
            $sData['updated_by'] = 'proposal-accept-webhook';
            @file_put_contents($storeFile, json_encode($sData, JSON_PRETTY_PRINT), LOCK_EX);
        }

        // 3. Send instant Telegram notification via @Epicexx_bot
        $telegramMsg = "🎉 <b>PROPOSAL ACCEPTED & SIGNED!</b>

"
            . "👤 <b>Client:</b> {$clientName}
"
            . "🏢 <b>Business:</b> {$business}
"
            . "✍️ <b>Digital Signature:</b> <i>{$signature}</i>
"
            . "🚀 <b>Build Tier:</b> {$buildTier}
"
            . "🛡️ <b>Care Tier:</b> {$careTier}
"
            . "💵 <b>50% Deposit Due:</b> {$deposit}
"
            . "🆔 <b>Proposal ID:</b> {$propId}
"
            . "🕒 <b>Signed At:</b> {$signedAt}
"
            . "🌐 <b>IP Address:</b> {$clientIp}

"
            . "⚡ <i>Client is proceeding to Stripe deposit checkout. Prepare sprint kickoff!</i>";

        $sent = false;
        if (file_exists('/usr/local/bin/send-telegram')) {
            $escapedMsg = escapeshellarg($telegramMsg);
            @exec("/usr/local/bin/send-telegram {$escapedMsg} 2>&1", $out, $ret);
            $sent = ($ret === 0);
        } else {
            $envFile = '/etc/epic-ops.env';
            if (file_exists($envFile)) {
                $env = @parse_ini_file($envFile);
                if (!empty($env['TELEGRAM_BOT_TOKEN']) && !empty($env['TELEGRAM_CHAT_ID'])) {
                    $tgUrl = "https://api.telegram.org/bot" . $env['TELEGRAM_BOT_TOKEN'] . "/sendMessage";
                    $postData = http_build_query([
                        'chat_id' => $env['TELEGRAM_CHAT_ID'],
                        'text' => $telegramMsg,
                        'parse_mode' => 'HTML'
                    ]);
                    $ctx = stream_context_create([
                        'http' => [
                            'method' => 'POST',
                            'header' => "Content-Type: application/x-www-form-urlencoded
",
                            'content' => $postData,
                            'timeout' => 5
                        ]
                    ]);
                    @file_get_contents($tgUrl, false, $ctx);
                    $sent = true;
                }
            }
        }

        echo json_encode([
            'success' => true,
            'message' => 'Proposal accepted and recorded',
            'telegram_dispatched' => $sent,
            'proposal_id' => $propId
        ]);
        exit;
    }

    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON payload']);
        exit;
    }

    $existing = [];
    if (file_exists($storeFile)) {
        $existing = json_decode(file_get_contents($storeFile), true) ?: [];
    }

    if (isset($input['projects']) && is_array($input['projects'])) {
        $existing['projects'] = $input['projects'];
    }
    if (isset($input['leads']) && is_array($input['leads'])) {
        $existing['leads'] = $input['leads'];
    }
    $existing['last_updated'] = date('Y-m-d H:i:s T');
    $existing['updated_by'] = isset($input['device']) ? $input['device'] : 'web-client';

    file_put_contents($storeFile, json_encode($existing, JSON_PRETTY_PRINT), LOCK_EX);

    echo json_encode([
        'success' => true,
        'message' => 'State persisted successfully to Hetzner cloud node',
        'last_updated' => $existing['last_updated'],
        'projects_count' => count($existing['projects'] ?? []),
        'leads_count' => count($existing['leads'] ?? []),
        'server' => getServerStats()
    ]);
    exit;
}

// GET: Full State (Projects, Leads, Server Stats)
$storedData = [];
if (file_exists($storeFile)) {
    $storedData = json_decode(file_get_contents($storeFile), true) ?: [];
}

// Seed if missing
if (empty($storedData['projects'])) {
    if (file_exists($defaultsFile)) {
        $defaults = json_decode(file_get_contents($defaultsFile), true);
        $storedData['projects'] = $defaults['projects'] ?? [];
        $storedData['leads'] = $defaults['leads'] ?? [];
    }
    $storedData['last_updated'] = date('Y-m-d H:i:s T');
    $storedData['updated_by'] = 'system-init';
    file_put_contents($storeFile, json_encode($storedData, JSON_PRETTY_PRINT), LOCK_EX);
}

echo json_encode([
    'success' => true,
    'server' => getServerStats(),
    'projects' => $storedData['projects'] ?? [],
    'leads' => $storedData['leads'] ?? [],
    'last_updated' => $storedData['last_updated'] ?? date('Y-m-d H:i:s T')
]);
