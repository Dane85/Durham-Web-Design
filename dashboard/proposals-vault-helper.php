<?php
// proposals-vault-helper.php - Centralized synchronization for Proposals/ Vault

function syncProposalToVault($prop) {
    $targetDirs = [];
    $serverDir = dirname(__DIR__) . '/proposals';
    $targetDirs[] = $serverDir;
    $wsDir = dirname(dirname(__DIR__)) . '/Proposals';
    if (is_dir($wsDir) && !in_array($wsDir, $targetDirs)) {
        $targetDirs[] = $wsDir;
    }

    $client = trim($prop['contact'] ?? $prop['client'] ?? 'Client');
    $business = trim($prop['business'] ?? $prop['biz'] ?? 'Business');

    $sanitized = preg_replace('/[\\/:*?"<>|]/', '', "{$client} - {$business}");
    if (empty(trim($sanitized))) {
        $sanitized = "Proposal - " . ($prop['id'] ?? time());
    }

    $statusLabel = '🟡 Generated / Ready to Send';
    if (($prop['status'] ?? '') === 'accepted') {
        $statusLabel = '🟢 Accepted';
    } elseif (($prop['status'] ?? '') === 'sent') {
        $statusLabel = '🟡 Sent';
    } elseif (($prop['status'] ?? '') === 'lead') {
        $statusLabel = '🟡 Lead';
    }

    $depAmount = isset($prop['deposit']) ? number_format($prop['deposit'], 2) : '0.00';
    $totAmount = isset($prop['total_price']) ? number_format($prop['total_price'], 2) : '0.00';
    $moAmount = isset($prop['monthly']) ? number_format($prop['monthly'], 2) : '0.00';
    $tierTitle = $prop['tier_title'] ?? ucfirst($prop['tier'] ?? 'Starter');
    $pUrl = $prop['proposal_url'] ?? '';
    $phone = !empty($prop['phone']) ? $prop['phone'] : 'On File';
    $email = !empty($prop['email']) ? $prop['email'] : 'On File';
    $pId = $prop['id'] ?? ('prop-' . time());
    $depLink = $prop['stripe_deposit_url'] ?? '';
    $fullLink = $prop['stripe_full_url'] ?? '';
    $careLink = $prop['stripe_care_url'] ?? '';

    // If stripe links missing, inject defaults based on tier
    $tierKey = strtolower($prop['tier'] ?? 'starter');
    $stripeDefaults = [
        'starter' => [
            'deposit' => 'https://buy.stripe.com/aFacN4cE6eJp2MXd5ebZe00',
            'full' => 'https://buy.stripe.com/bJe28q33wdFlcnx7KUbZe03',
            'care' => 'https://buy.stripe.com/7sY8wOcE66cT0EPd5ebZe06'
        ],
        'growth' => [
            'deposit' => 'https://buy.stripe.com/6oU4gy8nQ8l19blghqbZe01',
            'full' => 'https://buy.stripe.com/5kQ7sKfQi44Lbjt3uEbZe04',
            'care' => 'https://buy.stripe.com/4gM5kC0VobxdevF1mwbZe07'
        ],
        'custom' => [
            'deposit' => 'https://buy.stripe.com/7sY4gy33w6cTafpfdmbZe02',
            'full' => 'https://buy.stripe.com/7sYaEWeMe9p54V51mwbZe05',
            'care' => 'https://buy.stripe.com/3cIfZgavY30H9bl1mwbZe08'
        ],
        'draa' => [
            'deposit' => 'https://buy.stripe.com/aFaeVc8nQ0SzgDN8OYbZe0a',
            'full' => 'https://buy.stripe.com/bJe3cu33wat973d5CMbZe0b',
            'care' => 'https://buy.stripe.com/3cIeVcdIaat91IT5CMbZe0c'
        ]
    ];
    if (empty($depLink) && isset($stripeDefaults[$tierKey])) {
        $depLink = $stripeDefaults[$tierKey]['deposit'];
        $prop['stripe_deposit_url'] = $depLink;
    }
    if (empty($fullLink) && isset($stripeDefaults[$tierKey])) {
        $fullLink = $stripeDefaults[$tierKey]['full'];
        $prop['stripe_full_url'] = $fullLink;
    }
    if (empty($careLink) && isset($stripeDefaults[$tierKey])) {
        $careLink = $stripeDefaults[$tierKey]['care'];
        $prop['stripe_care_url'] = $careLink;
    }

    $signatureBlock = "";
    if (!empty($prop['signature'])) {
        $sigTime = $prop['accepted_at'] ?? (date('Y-m-d H:i:s') . ' UTC');
        $sigIp = $prop['client_ip'] ?? 'Recorded';
        $signatureBlock = "## ✍️ Digital Authorization & Acceptance\n\n"
            . "- **Authorized By:** `{$prop['signature']}`\n"
            . "- **Signed At:** `{$sigTime}`\n"
            . "- **Verification IP:** `{$sigIp}`\n"
            . "- **Agreement Status:** `🟢 Legally Authorized & Locked`\n\n"
            . "---\n\n";
    }

    $mdContent = "# 📄 Website Engineering Proposal — {$business}\n\n"
        . "**Client:** {$client}  \n"
        . "**Entity:** {$business}  \n"
        . "**Email:** " . ($email !== 'On File' ? "`$email`" : "On File") . "  \n"
        . "**Phone:** " . ($phone !== 'On File' ? "$phone" : "On File") . "  \n"
        . "**Proposal ID:** `{$pId}`  \n"
        . "**Target URL:** `{$pUrl}`  \n"
        . "**Status:** `{$statusLabel}`  \n"
        . "**Date Generated:** " . date('F d, Y') . "  \n\n"
        . "---\n\n"
        . "## 💰 Investment Terms\n\n"
        . "| Component | Amount | Schedule |\n"
        . "| :--- | :--- | :--- |\n"
        . "| **Total Engineering Investment** | **\${$totAmount} CAD** | {$tierTitle} |\n"
        . "| **50% Initiation Deposit** | **\${$depAmount} CAD** | Due to lock engineering calendar |\n"
        . "| **Final Launch Balance** | **\${$depAmount} CAD** | Due upon private staging approval |\n"
        . "| **Managed Cloud Care Retainer** | **\${$moAmount} CAD / mo** | Billed monthly starting 30 days post-launch |\n\n"
        . "---\n\n"
        . "## ⚡ Direct Stripe Payment Rails\n\n"
        . ($depLink ? "- ⚡ **50% Deposit (\${$depAmount} CAD):**  \n  [{$depLink}]({$depLink})\n\n" : "")
        . ($fullLink ? "- 💳 **Full Balance (\${$totAmount} CAD):**  \n  [{$fullLink}]({$fullLink})\n\n" : "")
        . ($careLink ? "- 🔄 **Monthly Care Retainer (\${$moAmount} CAD/mo):**  \n  [{$careLink}]({$careLink})\n\n" : "")
        . "---\n\n"
        . $signatureBlock
        . "## 🔗 Action Links\n"
        . "- [Open Interactive Proposal Closer]({$pUrl})\n";

    foreach ($targetDirs as $vaultDir) {
        if (!is_dir($vaultDir)) {
            @mkdir($vaultDir, 0775, true);
        }

        // 1. Write individual Markdown card
        $mdPath = $vaultDir . '/' . $sanitized . '.md';
        @file_put_contents($mdPath, $mdContent, LOCK_EX);

        // 2. Update proposals.json in vault
        $vJsonPath = $vaultDir . '/proposals.json';
        $vList = [];
        if (file_exists($vJsonPath)) {
            $vList = json_decode(file_get_contents($vJsonPath), true) ?: [];
        }
        $found = false;
        foreach ($vList as &$item) {
            if (strcasecmp($item['id'] ?? '', $pId) === 0 || (!empty($business) && strcasecmp($item['business'] ?? '', $business) === 0)) {
                $item = array_merge($item, $prop);
                $found = true;
                break;
            }
        }
        unset($item);
        if (!$found) {
            array_unshift($vList, $prop);
        }
        @file_put_contents($vJsonPath, json_encode($vList, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

        // 3. Rebuild README.md Master Table
        $readmePath = $vaultDir . '/README.md';
        $tableRows = "";
        $recordLinks = "";
        foreach ($vList as $p) {
            $pClient = $p['contact'] ?? $p['client'] ?? 'Client';
            $pBiz = $p['business'] ?? $p['biz'] ?? 'Business';
            $pTot = isset($p['total_price']) ? number_format($p['total_price'], 2) : '0.00';
            $pDep = isset($p['deposit']) ? number_format($p['deposit'], 2) : '0.00';
            $pMo = isset($p['monthly']) ? number_format($p['monthly'], 2) : '0.00';
            $pTier = $p['tier_title'] ?? ucfirst($p['tier'] ?? 'Starter');
            $pDepUrl = $p['stripe_deposit_url'] ?? '';
            $pPropUrl = $p['proposal_url'] ?? '#';
            $st = $p['status'] ?? 'sent';
            $stBadge = ($st === 'accepted') ? '🟢 Accepted' : (($st === 'lead') ? '🟡 Lead' : '🟡 ' . ucfirst($st));
            
            $depCol = "**\${$pDep} CAD**";
            if ($pDepUrl) {
                $depCol .= "<br>[Stripe Link]({$pDepUrl})";
            }
            
            $tableRows .= "| **{$pClient}** | {$pBiz} | **{$pTier}**<br>\${$pTot} CAD + \${$pMo}/mo | {$depCol} | `{$stBadge}` | [Open Proposal]({$pPropUrl}) |\n";
            $recName = preg_replace('/[\\/:*?"<>|]/', '', "{$pClient} - {$pBiz}");
            $recordLinks .= "- [[{$recName}]]\n";
        }

        $readmeContent = "# 📄 Client Proposals Vault\n"
            . "> **AGENCY SALES & CLOSER REPOSITORY**  \n"
            . "> *This folder contains all active client proposals generated by Durham Web Design & Epic Expressions. Whenever a new proposal link is generated, a markdown record is automatically saved here.*\n\n"
            . "---\n\n"
            . "## 📋 Active Client Proposals Master Index\n\n"
            . "| Client Name | Business / Entity | Tier & Investment | 50% Deposit | Status | Live Proposal Closer Link |\n"
            . "| :--- | :--- | :--- | :--- | :---: | :--- |\n"
            . $tableRows
            . "\n---\n\n"
            . "## ⚡ How to Generate a New Proposal Link\n\n"
            . "When you want to send a proposal to a new client, you can use any of the following 3 ways:\n\n"
            . "### Method 1: Instant URL Pattern\n"
            . "Simply format the URL with client parameters:\n"
            . "```\n"
            . "https://durhamweb.design/proposal/?client=[Name]&business=[Business+Name]&tier=[starter|growth|custom|draa]&phone=[Phone]\n"
            . "```\n\n"
            . "### Method 2: Command Line Generator\n"
            . "Run in PowerShell:\n"
            . "```powershell\n"
            . "powershell -ExecutionPolicy Bypass -File \"scripts\\new_proposal.ps1\" -Client \"John Doe\" -Business \"Whitby Electric\" -Tier \"growth\" -Phone \"9055551234\"\n"
            . "```\n\n"
            . "### Method 3: Operations Dashboard / Epic OS\n"
            . "Use the **Proposal Generator** inside `https://ops.durhamweb.design` or `https://os.durhamweb.design/os` (Agency CRM module). It automatically commits to MariaDB and writes a markdown file here!\n\n"
            . "---\n\n"
            . "## 📂 Individual Proposal Records\n"
            . $recordLinks;

        @file_put_contents($readmePath, $readmeContent, LOCK_EX);
    }
}
