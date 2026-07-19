<?php
declare(strict_types=1);

/**
 * bin/seed_demo.php — LOCAL DEV. Seeds a spread of realistic tickets (varied status,
 * priority, replies, notes, a resolved one, a breached one) so the dashboard and
 * reports have data to show. Refuses to run in production.
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
use App\Services\TicketService;

if (Config::isProduction()) {
    fwrite(STDERR, "Refusing to run in production.\n");
    exit(1);
}

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
foreach ($samples as [$subject, $desc, $email, $priority, $followup]) {
    $r = TicketService::create([
        'subject' => $subject, 'description' => $desc, 'customer_email' => $email,
        'customer_name' => explode('@', $email)[0], 'priority' => $priority,
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

fwrite(STDOUT, "Seeded {$made} demo tickets into '" . Config::string('DB_NAME') . "'.\n");
