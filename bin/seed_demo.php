<?php
declare(strict_types=1);

/**
 * bin/seed_demo.php — LOCAL DEV. Seeds organizations (tenants), links the seed agents
 * to them, and creates a spread of realistic tickets across organizations (so the
 * multi-tenant dashboard, isolation, and reports have data). Refuses in production.
 *
 *   php bin/seed_demo.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Db;
use App\Models\KbArticle;
use App\Models\Organization;
use App\Models\RoutingRule;
use App\Models\User;
use App\Services\TicketService;

if (Config::isProduction()) {
    fwrite(STDERR, "Refusing to run in production.\n");
    exit(1);
}

// ── Organizations + agent links (only if none exist) ────────────────────────
$orgIds = [];
if ((int) Db::scalar('SELECT COUNT(*) FROM organizations') === 0) {
    foreach (['Acme Corporation', 'Northwind Traders', 'Globex Industries'] as $name) {
        $orgIds[] = Organization::create(['name' => $name, 'active' => 1]);
    }
    // Link the two seed agents to the first two organizations (one org per agent).
    $a1 = User::findByEmail('agent1@p3a-support.com.ng');
    $a2 = User::findByEmail('agent2@p3a-support.com.ng');
    if ($a1) { User::update((int) $a1['id'], ['organization_id' => $orgIds[0]]); }
    if ($a2) { User::update((int) $a2['id'], ['organization_id' => $orgIds[1]]); }
} else {
    $orgIds = array_map(static fn(array $o): string => (string) $o['organization_id'], Organization::allActive());
}
$orgFor = static function (int $i) use ($orgIds): string {
    // Rotate: org A, org B, general (no org) — so each agent + the general queue get some.
    if ($orgIds === []) { return ''; }
    $slot = $i % 3;
    return $slot < count($orgIds) && $slot !== 2 ? $orgIds[$slot] : '';
};

$agent = ['name' => 'Agent One', 'email' => 'agent1@p3a-support.com.ng', 'role' => 'agent'];

$samples = [
    ['Cannot reset my password', 'The reset link says it expired every time.', 'grace@northwind.example', 'high', 'reply'],
    ['Invoice #4471 looks wrong', 'I was charged twice this month.', 'omar@brightsea.example', 'normal', 'note'],
    ['App crashes on export', 'Clicking Export to CSV closes the whole tab.', 'lena@fjordtech.example', 'urgent', 'reply'],
    ['Feature request: dark mode', 'Would love a dark theme for the portal.', 'sam@meadowlark.example', 'low', 'none'],
    ['Login loops back to sign-in', 'After entering my code it returns to login.', 'ravi@quanta.example', 'high', 'resolve'],
    ['Where do I download receipts?', 'I need last quarter for accounting.', 'yuki@sakura.example', 'normal', 'resolve'],
    ['Account locked', 'Too many attempts — I need it unlocked.', 'tom@harborline.example', 'normal', 'note'],
    ['Onboarding call follow-up', 'Sending the notes from our call as promised.', 'nadia@vela.example', 'low', 'none'],
];

$made = 0;
foreach ($samples as $i => [$subject, $desc, $email, $priority, $followup]) {
    $r = TicketService::create([
        'subject' => $subject, 'description' => $desc, 'customer_email' => $email,
        'customer_name' => explode('@', $email)[0], 'priority' => $priority,
        'organization_id' => $orgFor($i),
    ], 'web_form');
    if (($r['ok'] ?? false) !== true) {
        continue;
    }
    $tid = (string) $r['ticket']['ticket_id'];
    $made++;
    if ($followup === 'reply') {
        TicketService::reply($tid, "Thanks for reaching out — we're looking into this now.", $agent);
    } elseif ($followup === 'note') {
        TicketService::addInternalNote($tid, "Internal: check billing logs before replying.", $agent);
    } elseif ($followup === 'resolve') {
        TicketService::reply($tid, "This should be sorted now — let us know if not.", $agent);
        TicketService::changeStatus($tid, 'resolved', $agent);
    }
}

// Make one ticket visibly SLA-breached for the KPI/queue highlight.
$overdue = gmdate('Y-m-d H:i:s', time() - 3 * 86400);
Db::query(
    "UPDATE tickets SET sla_response_deadline = :d1, sla_resolution_deadline = :d2,
            sla_response_status = 'breached', sla_resolution_status = 'breached', priority = 'urgent'
     WHERE status IN ('open','pending') ORDER BY id ASC LIMIT 1",
    [':d1' => $overdue, ':d2' => $overdue]
);

// A few starter knowledge-base articles (only if none exist yet).
$kbMade = 0;
if ((int) Db::scalar('SELECT COUNT(*) FROM knowledge_base') === 0) {
    $articles = [
        ['How to reset your password', "1. On the sign-in page, click \"Forgot password?\".\n2. Enter your account email.\n3. Open the reset link we email you (valid 60 minutes, single use).\n4. Choose a new password of at least 12 characters.\n\nIf the link says it expired, request a fresh one — each link can only be used once.", 'public', 'Account'],
        ['Understanding ticket priorities', "Urgent — production is down or there's a security issue.\nHigh — significant impact, but a workaround exists.\nNormal — a standard request.\nLow — a question or minor cosmetic issue.\n\nSLA response and resolution timers are set from the priority at the moment a ticket is created, and recalculated if the priority changes.", 'public', 'Getting Started'],
        ['Checking the status of your ticket', "Use the \"Check status\" page with your ticket ID (format TKT-YYYY-NNNN) and the email address you submitted with. You'll see the current status and the latest reply from our team. For your privacy, internal notes are never shown.", 'public', 'Getting Started'],
        ['Escalation playbook (internal)', "For urgent tickets breaching SLA:\n1. Reassign to a senior agent.\n2. Add an internal note with the full context and what's been tried.\n3. Notify the duty admin.\n\nNever paste internal notes into a customer-facing reply.", 'internal', 'Operations'],
    ];
    foreach ($articles as [$title, $body, $vis, $cat]) {
        KbArticle::create(['title' => $title, 'body' => $body, 'visibility' => $vis, 'category' => $cat, 'author' => $agent['email']]);
        $kbMade++;
    }
}

// Sample routing rules (only if none exist) — built-in actions, no external refs.
$ruleMade = 0;
if ((int) Db::scalar('SELECT COUNT(*) FROM routing_rules') === 0) {
    $rules = [
        ['Billing keywords → High priority',
            [['field' => 'subject', 'operator' => 'contains', 'value' => 'invoice']],
            [['type' => 'set_priority', 'value' => 'high']]],
        ['Refund requests → tag + High',
            [['field' => 'subject', 'operator' => 'contains', 'value' => 'refund']],
            [['type' => 'set_priority', 'value' => 'high'], ['type' => 'add_tag', 'value' => 'refund']]],
        ['Outage wording → Urgent',
            [['field' => 'description', 'operator' => 'contains', 'value' => 'down']],
            [['type' => 'set_priority', 'value' => 'urgent'], ['type' => 'add_tag', 'value' => 'outage']]],
    ];
    foreach ($rules as [$name, $conditions, $actions]) {
        RoutingRule::create([
            'rule_id'    => RoutingRule::nextRuleId(),
            'name'       => $name,
            'enabled'    => true,
            'conditions' => json_encode($conditions),
            'actions'    => json_encode($actions),
            'sort_order' => RoutingRule::maxSortOrder() + 1,
        ]);
        $ruleMade++;
    }
}

fwrite(STDOUT, "Seeded " . count($orgIds) . " organizations, {$made} tickets, {$kbMade} KB articles, {$ruleMade} routing rules into '" . Config::string('DB_NAME') . "'.\n");
