<?php
declare(strict_types=1);

/**
 * Phase 7 test (§17.1) — Admin panel.
 * Exit criteria: lockout guards proven; a DISABLED routing rule provably does not
 * fire; ticket-data reset preserves audit + config and requires the exact typed
 * phrase server-side. Plus SLA-target and config-allowlist validation.
 */

require __DIR__ . '/../lib.php';

$root = dirname(__DIR__, 2);
if (!defined('P3A_ROOT')) {
    define('P3A_ROOT', $root);
}
require $root . '/vendor/autoload.php';

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\Dispatch;
use App\Core\Request;
use App\Core\Session;
use App\Models\AppConfig;
use App\Models\Organization;
use App\Models\RoutingRule;
use App\Models\User;
use App\Services\ResetService;
use App\Services\TicketService;

$envTesting = $root . '/.env.testing';
if (!is_file($envTesting)) {
    T::note('.env.testing not found — SKIPPING Phase 7');
    exit(T::summary());
}
Config::load($envTesting);

try {
    $pdo = Db::connect();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $pdo->exec("DROP TABLE IF EXISTS `$t`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $pdo->exec((string) file_get_contents("$root/database/schema.sql"));
    $pdo->exec((string) file_get_contents("$root/database/seed.sql"));
} catch (Throwable $e) {
    T::ok(false, 'schema/seed load: ' . $e->getMessage());
    exit(T::summary());
}

$admin = User::findByEmail('admin@p3a-support.com.ng');
Session::start((int) $admin['id'], (string) $admin['email'], 'admin', '203.0.113.50', 'ua', true);
$server = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api', 'REMOTE_ADDR' => '203.0.113.50', 'CONTENT_TYPE' => 'application/json'];
$call = static function (string $action, array $payload) use ($server): array {
    $req = new Request(post: ['action' => $action, 'payload' => $payload, 'csrf' => Csrf::token()], server: $server);
    $resp = Dispatch::handle($req);
    return [$resp->status(), json_decode($resp->body(), true)];
};
$backups = [];

// ── Users CRUD + lockout guards (§3) ─────────────────────────────────────────
T::suite('Phase 7: users CRUD + guards');
[$st, $body] = $call('createUser', ['name' => 'New Agent', 'email' => 'newagent@example.com', 'role' => 'agent', 'password' => 'a-brand-new-agent-2026']);
T::eq(200, $st, 'createUser → 200');
$newAgentId = $body['data']['id'] ?? 0;
T::ok(str_starts_with((string) ($body['data']['public_id'] ?? ''), 'AG-'), 'agent gets an AG- public id');
T::ok((int) User::findById($newAgentId)['must_change_pw'] === 1, 'admin-set password forces must_change_pw');
T::ok((int) Db::scalar("SELECT COUNT(*) FROM mail_log WHERE subject LIKE 'Welcome to %'") >= 1, 'new user gets a welcome email (set-password link)');

// duplicate email rejected
[$st] = $call('createUser', ['name' => 'Dup', 'email' => 'newagent@example.com', 'role' => 'agent', 'password' => 'another-strong-pass-1']);
T::eq(422, $st, 'duplicate email rejected');
// weak password rejected
[$st] = $call('createUser', ['name' => 'Weak', 'email' => 'weak@example.com', 'role' => 'agent', 'password' => 'password']);
T::eq(422, $st, 'breached/weak password rejected');

// self-guards
[$st, $body] = $call('deactivateUser', ['id' => (int) $admin['id']]);
T::eq(422, $st, 'cannot deactivate your own account');
[$st, $body] = $call('deleteUser', ['id' => (int) $admin['id']]);
T::eq(422, $st, 'cannot delete your own account');

// last-active-admin guard via role demotion (seed has exactly one admin)
T::eq(1, User::activeAdminCount(), 'one active admin at start');
[$st, $body] = $call('updateUser', ['id' => (int) $admin['id'], 'role' => 'agent']);
T::eq(422, $st, 'cannot demote the last active admin');
T::ok(str_contains($body['error'] ?? '', 'last active admin'), 'guard message explains why');

// deactivate terminates the target's sessions (§10.4)
Session::start($newAgentId, 'newagent@example.com', 'agent', '203.0.113.51', 'ua', true);
T::ok((int) Db::scalar('SELECT COUNT(*) FROM sessions WHERE user_id = :u', [':u' => $newAgentId]) > 0, 'target has a live session');
// restore admin session context for the gateway call
Session::start((int) $admin['id'], (string) $admin['email'], 'admin', '203.0.113.50', 'ua', true);
[$st] = $call('deactivateUser', ['id' => $newAgentId]);
T::eq(200, $st, 'deactivate a normal agent → 200');
T::eq(0, (int) Db::scalar('SELECT COUNT(*) FROM sessions WHERE user_id = :u', [':u' => $newAgentId]), 'deactivation terminated the agent\'s sessions');

// ── SLA targets validation (§3) ──────────────────────────────────────────────
T::suite('Phase 7: SLA targets');
$slaOk = ['sla_response_urgent' => 15, 'sla_resolution_urgent' => 120, 'sla_response_high' => 60, 'sla_resolution_high' => 240, 'sla_response_normal' => 240, 'sla_resolution_normal' => 480, 'sla_response_low' => 480, 'sla_resolution_low' => 1440];
[$st] = $call('updateSlaTargets', $slaOk);
T::eq(200, $st, 'valid SLA targets accepted');
T::eq('15', AppConfig::get('sla_response_urgent'), 'SLA config value written');
[$st] = $call('updateSlaTargets', array_merge($slaOk, ['sla_resolution_high' => 30]));
T::eq(422, $st, 'resolution < response rejected');
[$st] = $call('updateSlaTargets', array_merge($slaOk, ['sla_response_low' => -5]));
T::eq(422, $st, 'negative SLA minutes rejected');

// ── config allowlist (§3, §10.5) ─────────────────────────────────────────────
T::suite('Phase 7: config allowlist');
[$st, $body] = $call('updateConfig', ['company_name' => 'Acme Support', 'brand_color' => '#00AA55', 'not_a_key' => 'x', 'sla_response_urgent' => '999']);
T::eq(200, $st, 'updateConfig accepts allowlisted keys');
T::eq('Acme Support', AppConfig::get('company_name'), 'allowlisted key written');
T::eq('15', AppConfig::get('sla_response_urgent'), 'non-allowlisted key ignored (mass-assignment blocked)');
[$st] = $call('updateConfig', ['brand_color' => 'notacolor']);
T::eq(422, $st, 'invalid brand colour rejected');

// ── routing rules: disabled rule does NOT fire (§18) ─────────────────────────
T::suite('Phase 7: routing rules — disabled never fires (§18)');
[$st, $body] = $call('createRule', [
    'name' => 'Refund → high',
    'enabled' => true,
    'conditions' => [['field' => 'subject', 'operator' => 'contains', 'value' => 'refund']],
    'actions' => [['type' => 'set_priority', 'value' => 'high']],
]);
T::eq(200, $st, 'rule created');
$ruleId = $body['data']['rule_id'];

// invalid action rejected
[$st] = $call('createRule', ['name' => 'bad', 'enabled' => true, 'conditions' => [['field' => 'subject', 'operator' => 'is', 'value' => 'x']], 'actions' => [['type' => 'nope', 'value' => 'y']]]);
T::eq(422, $st, 'invalid action type rejected on write');

$submit = static fn(): string => (string) TicketService::create(
    ['subject' => 'Please issue a refund', 'description' => 'body', 'customer_email' => 'c@example.com', 'priority' => 'normal'],
    'web_form'
)['ticket']['priority'];

T::eq('high', $submit(), 'ENABLED rule fires: matching ticket escalated to high');

// disable the rule → same submission must NOT be escalated
[$st] = $call('toggleRule', ['rule_id' => $ruleId, 'enabled' => false]);
T::eq(200, $st, 'rule disabled');
T::eq('normal', $submit(), 'DISABLED rule does NOT fire: priority stays normal (§18)');

// re-enable to confirm the toggle truly gates
$call('toggleRule', ['rule_id' => $ruleId, 'enabled' => true]);
T::eq('high', $submit(), 'rule re-enabled: escalation returns (toggle genuinely gates)');

// EDIT the rule (updateRule): new condition + action must take effect, old must not
[$st] = $call('updateRule', [
    'rule_id' => $ruleId,
    'name' => 'Credit → urgent',
    'enabled' => true,
    'conditions' => [['field' => 'subject', 'operator' => 'contains', 'value' => 'credit']],
    'actions' => [['type' => 'set_priority', 'value' => 'urgent']],
]);
T::eq(200, $st, 'updateRule (edit) succeeds');
$submitCredit = static fn(): string => (string) TicketService::create(
    ['subject' => 'Please apply a credit', 'description' => 'body', 'customer_email' => 'c@example.com', 'priority' => 'normal'],
    'web_form'
)['ticket']['priority'];
T::eq('urgent', $submitCredit(), 'edited rule fires with the new condition + action');
T::eq('normal', $submit(), 'the old condition no longer matches after the edit');

// ── ticket-data reset (§3, §4, §10.11, §18) ──────────────────────────────────
T::suite('Phase 7: ticket-data reset');
$auditBefore = (int) Db::scalar('SELECT COUNT(*) FROM audit_log');
$configBefore = (int) Db::scalar('SELECT COUNT(*) FROM config');
$usersBefore = (int) Db::scalar('SELECT COUNT(*) FROM users');
$catsBefore = (int) Db::scalar('SELECT COUNT(*) FROM categories');
$ticketsBefore = (int) Db::scalar('SELECT COUNT(*) FROM tickets');
T::ok($ticketsBefore > 0, "there are tickets to reset ({$ticketsBefore})");

// wrong phrase → nothing happens
[$st, $body] = $call('resetTicketData', ['confirm' => 'reset ticket data']); // wrong case
T::eq(422, $st, 'wrong confirmation phrase → 422');
T::eq($ticketsBefore, (int) Db::scalar('SELECT COUNT(*) FROM tickets'), 'no tickets deleted on a bad phrase');

// exact phrase → reset
[$st, $body] = $call('resetTicketData', ['confirm' => ResetService::CONFIRM_PHRASE]);
T::eq(200, $st, 'exact phrase → reset runs');
if (!empty($body['data']['backup'])) {
    $backups[] = \App\Services\BackupService::backupDir() . '/' . $body['data']['backup'];
}
T::eq(0, (int) Db::scalar('SELECT COUNT(*) FROM tickets'), 'tickets deleted');
T::eq(0, (int) Db::scalar('SELECT COUNT(*) FROM messages'), 'messages deleted (cascade)');
T::eq(0, (int) Db::scalar('SELECT COUNT(*) FROM attachments'), 'attachments deleted (cascade)');

// preserved by design (§10.11)
T::ok((int) Db::scalar('SELECT COUNT(*) FROM audit_log') >= $auditBefore, 'audit log PRESERVED (survives reset)');
T::ok((int) Db::scalar("SELECT COUNT(*) FROM audit_log WHERE action='ticket_data_reset'") === 1, 'reset event appended to the audit log');
T::eq($configBefore, (int) Db::scalar('SELECT COUNT(*) FROM config'), 'config PRESERVED');
T::eq($usersBefore, (int) Db::scalar('SELECT COUNT(*) FROM users'), 'users PRESERVED');
T::eq($catsBefore, (int) Db::scalar('SELECT COUNT(*) FROM categories'), 'categories PRESERVED');
T::ok((int) Db::scalar("SELECT COUNT(*) FROM notifications WHERE type='system_alert'") >= 1, 'all admins alerted (§4)');
if (!empty($body['data']['backup'])) {
    T::ok(is_file(\App\Services\BackupService::backupDir() . '/' . $body['data']['backup']), 'a backup file was written before the delete (§18)');
}

// ── Organization Admin role: org-scoped user management + system lockout ─────
T::suite('Phase 7: organization admin role');
Session::destroy();
Session::start((int) $admin['id'], (string) $admin['email'], 'admin', '203.0.113.50', 'ua', true);
$orgX = Organization::create(['name' => 'Org X', 'active' => 1]);
$orgY = Organization::create(['name' => 'Org Y', 'active' => 1]);
[$st, $ob] = $call('createUser', ['name' => 'OA X', 'email' => 'oax@ex.com', 'role' => 'org_admin', 'organization_id' => $orgX, 'password' => 'org-admin-strong-2026']);
T::eq(200, $st, 'system admin creates an org_admin');
$oaId = (int) $ob['data']['id'];
$call('createUser', ['name' => 'Agent X', 'email' => 'agx@ex.com', 'role' => 'agent', 'organization_id' => $orgX, 'password' => 'agent-x-strong-2026']);
$call('createUser', ['name' => 'Agent Y', 'email' => 'agy@ex.com', 'role' => 'agent', 'organization_id' => $orgY, 'password' => 'agent-y-strong-2026']);
$agentX = User::findByEmail('agx@ex.com');
$agentY = User::findByEmail('agy@ex.com');

// act AS the org admin
Session::destroy();
Session::start($oaId, 'oax@ex.com', 'org_admin', '203.0.113.51', 'ua', true);
[$st, $b] = $call('listUsers', []);
$emails = array_map(static fn($u) => $u['email'], $b['data']['users'] ?? []);
T::ok(in_array('agx@ex.com', $emails, true), 'org admin sees agents in their own org');
T::ok(!in_array('agy@ex.com', $emails, true), 'org admin does NOT see another org\'s agents');

// creating a user: forced to role=agent in the org admin's OWN org (payload is ignored)
[$st, $b] = $call('createUser', ['name' => 'New Ag', 'email' => 'newag@ex.com', 'role' => 'admin', 'organization_id' => $orgY, 'password' => 'new-agent-strong-2026']);
T::eq(200, $st, 'org admin can add an agent');
$newAg = User::findByEmail('newag@ex.com');
T::eq('agent', (string) $newAg['role'], 'org admin-created user is forced to role=agent (cannot mint admins)');
T::eq($orgX, (string) $newAg['organization_id'], 'org admin-created agent is forced into the org admin\'s own org');

// cannot touch another org's agent; can manage own org's agent
[$st] = $call('deactivateUser', ['id' => (int) $agentY['id']]);
T::eq(422, $st, 'org admin cannot manage an agent in another org');
[$st] = $call('deleteUser', ['id' => (int) $agentY['id']]);
T::eq(422, $st, 'org admin cannot delete an agent in another org');
[$st] = $call('deactivateUser', ['id' => (int) $agentX['id']]);
T::eq(200, $st, 'org admin can deactivate an agent in their own org');

// system controls are denied to an org admin
foreach ([['getSystemConfig', []], ['updateConfig', ['company_name' => 'Hijack']], ['updateSlaTargets', ['sla_response_urgent' => 10]], ['listOrganizations', []], ['listCategories', []], ['runBackup', []], ['listAuditLog', []], ['listBackups', []], ['listProducts', []]] as [$act, $pl]) {
    [$st] = $call($act, $pl);
    T::eq(403, $st, "org admin blocked from system action: {$act}");
}

// ── Products / Projects admin CRUD (shared list) ─────────────────────────────
T::suite('Phase 7: products/projects admin');
// restore the system-admin session (the org-admin section above switched it)
Session::start((int) $admin['id'], (string) $admin['email'], 'admin', '203.0.113.50', 'ua', true);

[$st, $b] = $call('createProduct', ['name' => 'Mobile App']);
T::eq(200, $st, 'admin creates a product');
$prodId = (string) ($b['data']['product_id'] ?? '');
T::ok(str_starts_with($prodId, 'PRD-'), 'product gets a PRD- id');

[$st] = $call('createProduct', ['name' => 'Mobile App']);
T::eq(422, $st, 'duplicate product name rejected');

[$st, $b] = $call('listProducts', []);
T::ok($st === 200 && count($b['data']['products']) >= 2, 'listProducts includes seeded + new');

[$st] = $call('updateProduct', ['product_id' => $prodId, 'name' => 'Mobile App v2']);
T::eq(200, $st, 'rename product → 200');
T::eq('Mobile App v2', (string) \App\Models\Product::find($prodId)['name'], 'rename persisted');

[$st] = $call('updateProduct', ['product_id' => $prodId, 'active' => 0]);
T::eq(200, $st, 'disable product → 200');
T::ok(!\App\Models\Product::existsActive($prodId), 'disabled product is not active');

// delete guard: a ticket using a product blocks its deletion (disable instead)
\App\Services\TicketService::create([
    'subject' => 'uses product', 'description' => 'd', 'customer_email' => 'p@ex.com',
    'customer_name' => 'P', 'priority' => 'normal', 'category_id' => 'CAT-001', 'product_id' => 'PRD-0001',
], 'web_form');
[$st] = $call('deleteProduct', ['product_id' => 'PRD-0001']);
T::eq(422, $st, 'cannot delete a product still used by a ticket');
// an unused product deletes cleanly
[$st] = $call('deleteProduct', ['product_id' => $prodId]);
T::eq(200, $st, 'delete an unused product → 200');

// ── Audit log viewer + backup list (admin) ───────────────────────────────────
T::suite('Phase 7: audit viewer + backups');
[$st, $b] = $call('listAuditLog', []);
T::ok($st === 200 && count($b['data']['rows']) >= 1, 'admin lists audit entries');
T::ok(($b['data']['total'] ?? 0) >= count($b['data']['rows']), 'total covers at least the returned page');
[$st, $b] = $call('listAuditLog', ['action' => 'product_create']);
T::ok($st === 200 && $b['data']['rows'] !== [] && $b['data']['rows'][0]['action'] === 'product_create', 'action filter narrows the log');
[$st, $b] = $call('listAuditLog', ['verify' => 1]);
T::ok($st === 200 && ($b['data']['chain_ok'] ?? false) === true, 'hash chain verifies intact on demand');

[$st, $b] = $call('runBackup', []);
T::eq(200, $st, 'runBackup → 200');
if (!empty($b['data']['file'])) {
    $backups[] = \App\Services\BackupService::backupDir() . '/' . $b['data']['file'];
}
[$st2, $b2] = $call('listBackups', []);
$names = array_map(static fn(array $f): string => (string) $f['name'], $b2['data']['backups'] ?? []);
T::ok($st2 === 200 && in_array((string) ($b['data']['file'] ?? ''), $names, true), 'listBackups includes the new backup file');

// ── cleanup ──────────────────────────────────────────────────────────────────
foreach ($backups as $b) {
    if (is_file($b)) { unlink($b); }
}

exit(T::summary());
