<?php
declare(strict_types=1);

/**
 * Phase 11 test (§17.1) — Modules.
 * Exit criteria: ownership isolation on notifications; KB delete is admin-only.
 * Plus KB public/internal visibility, view counts, search, and canned-response CRUD.
 * (The rule-editor "rebuild value control on type change" is a DOM behaviour in
 * rules.js — built + node-linted; its backend, createRule, is covered in Phase 7.)
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
use App\Models\KbArticle;
use App\Models\User;
use App\Services\NotificationService;

$envTesting = $root . '/.env.testing';
if (!is_file($envTesting)) {
    T::note('.env.testing not found — SKIPPING Phase 11');
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
$agent1 = User::findByEmail('agent1@p3a-support.com.ng');
$agent2 = User::findByEmail('agent2@p3a-support.com.ng');
$server = ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '203.0.113.80', 'CONTENT_TYPE' => 'application/json'];
$call = static function (string $action, array $payload) use ($server): array {
    $req = new Request(post: ['action' => $action, 'payload' => $payload, 'csrf' => Csrf::token()], server: $server);
    $resp = Dispatch::handle($req);
    return [$resp->status(), json_decode($resp->body(), true)];
};

// ── Notifications: ownership isolation ───────────────────────────────────────
T::suite('Phase 11: notification ownership isolation');
NotificationService::create('agent1@p3a-support.com.ng', 'assigned', 'A1 note', 'TKT-2026-0001');
NotificationService::create('agent2@p3a-support.com.ng', 'assigned', 'A2 note', 'TKT-2026-0002');

Session::start((int) $agent1['id'], 'agent1@p3a-support.com.ng', 'agent', '203.0.113.80', 'ua', true);
[$st, $body] = $call('getNotifications', []);
T::eq(200, $st, 'agent gets their notifications');
$mine = $body['data']['notifications'];
T::eq(1, count($mine), 'agent1 sees only their own notification');
T::eq('A1 note', $mine[0]['message'], 'it is the right notification');
$a1notif = $mine[0]['notif_id'];
$a2notif = Db::queryOne("SELECT notif_id FROM notifications WHERE agent_email='agent2@p3a-support.com.ng'")['notif_id'];

// agent1 cannot mark agent2's notification read (ownership scope)
[$st, $body] = $call('markNotificationRead', ['notif_id' => $a2notif]);
T::eq(422, $st, "agent1 marking agent2's notification → rejected (not theirs)");
T::eq(0, (int) Db::scalar("SELECT is_read FROM notifications WHERE notif_id = :n", [':n' => $a2notif]), "agent2's notification stays unread");

// agent1 CAN mark their own
[$st] = $call('markNotificationRead', ['notif_id' => $a1notif]);
T::eq(200, $st, 'agent1 marks their own notification read');
T::eq(1, (int) Db::scalar("SELECT is_read FROM notifications WHERE notif_id = :n", [':n' => $a1notif]), 'own notification now read');
[$st, $body] = $call('getUnreadCount', []);
T::eq(0, $body['data']['unread'], 'unread count reflects the change');

// ── Knowledge base: visibility, view counts, admin-only delete ───────────────
T::suite('Phase 11: knowledge base');
// agent publishes a public + an internal article
[$st, $body] = $call('publishKbArticle', ['title' => 'Public how-to', 'body' => 'reset your password like this', 'visibility' => 'public', 'category' => 'Accounts']);
T::eq(200, $st, 'agent publishes an article');
$pubId = $body['data']['article_id'];
[$st, $body] = $call('publishKbArticle', ['title' => 'Internal runbook', 'body' => 'secret ops steps', 'visibility' => 'internal', 'category' => 'Ops']);
$intId = $body['data']['article_id'];

// staff list sees both; public list sees only the public one
[$st, $body] = $call('getKbArticles', []);
T::eq(2, count($body['data']['articles']), 'staff KB list shows public + internal');

// public KB action (used unauthenticated) never returns internal
Session::destroy();
$pubReq = new Request(post: ['action' => 'getPublicKb', 'payload' => [], 'csrf' => Csrf::publicToken('getPublicKb')], server: $server);
$pubResp = Dispatch::handle($pubReq);
$pubBody = json_decode($pubResp->body(), true);
T::eq(1, count($pubBody['data']['articles']), 'public KB shows only the public article');
T::eq($pubId, $pubBody['data']['articles'][0]['article_id'], 'the public one, not the internal');

// view count increments on public read
$before = (int) KbArticle::find($pubId)['view_count'];
$viewReq = new Request(post: ['action' => 'getPublicKb', 'payload' => ['article_id' => $pubId], 'csrf' => Csrf::publicToken('getPublicKb')], server: $server);
Dispatch::handle($viewReq);
T::eq($before + 1, (int) KbArticle::find($pubId)['view_count'], 'view_count increments on read');

// requesting the internal article via the public action is refused
$intReq = new Request(post: ['action' => 'getPublicKb', 'payload' => ['article_id' => $intId], 'csrf' => Csrf::publicToken('getPublicKb')], server: $server);
T::eq(422, Dispatch::handle($intReq)->status(), 'internal article not reachable via the public action');

// KB delete is ADMIN-ONLY: agent → 403, admin → 200
Session::start((int) $agent1['id'], 'agent1@p3a-support.com.ng', 'agent', '203.0.113.80', 'ua', true);
[$st] = $call('deleteKbArticle', ['article_id' => $intId]);
T::eq(403, $st, 'agent cannot delete a KB article (admin-only)');
T::ok(KbArticle::find($intId) !== null, 'article still exists after the denied delete');

Session::start((int) $admin['id'], 'admin@p3a-support.com.ng', 'admin', '203.0.113.80', 'ua', true);
[$st] = $call('deleteKbArticle', ['article_id' => $intId]);
T::eq(200, $st, 'admin can delete a KB article');
T::ok(KbArticle::find($intId) === null, 'article removed');

// search finds by body text
[$st, $body] = $call('getKbArticles', ['search' => 'reset your password']);
T::eq(1, count($body['data']['articles']), 'KB search matches article body');

// ── Canned responses CRUD (agent) ────────────────────────────────────────────
T::suite('Phase 11: canned response management');
Session::start((int) $agent1['id'], 'agent1@p3a-support.com.ng', 'agent', '203.0.113.80', 'ua', true);
[$st, $body] = $call('createCannedResponse', ['title' => 'Greeting', 'body' => 'Hi {customerName}, re {ticketId}']);
T::eq(200, $st, 'agent creates a canned response');
$canId = $body['data']['response_id'];
[$st, $body] = $call('manageCannedResponses', []);
T::ok(count($body['data']['responses']) >= 3, 'canned list includes seed + new');
[$st] = $call('updateCannedResponse', ['response_id' => $canId, 'title' => 'Greeting v2', 'body' => 'Hello {customerName}']);
T::eq(200, $st, 'agent updates a canned response');
T::eq('Greeting v2', Db::queryOne('SELECT title FROM canned_responses WHERE response_id = :r', [':r' => $canId])['title'], 'title updated');
[$st] = $call('deleteCannedResponse', ['response_id' => $canId]);
T::eq(200, $st, 'agent deletes a canned response');
T::ok(Db::queryOne('SELECT 1 FROM canned_responses WHERE response_id = :r', [':r' => $canId]) === null, 'canned response removed');
// invalid create → 422
[$st] = $call('createCannedResponse', ['title' => '', 'body' => 'x']);
T::eq(422, $st, 'empty title rejected');

exit(T::summary());
