<?php
declare(strict_types=1);
/**
 * Public status lookup (Phase 9). Ticket id AND matching email both required.
 * Wrong-email and unknown-id return a byte-identical generic error (handled server-
 * side). Internal notes/system messages are never returned (anonymous visibility).
 *
 * @var string $csrf
 */
?>
<section class="p3a-public-form" data-form="status" data-csrf="<?= e($csrf) ?>">
    <h1>Check ticket status</h1>
    <p class="p3a-form-msg" data-bind="status-msg" role="status"></p>
    <form data-action="check-status">
        <label>Ticket ID<input type="text" name="ticket_id" required placeholder="TKT-2026-0001"></label>
        <label>Email<input type="email" name="email" required maxlength="254"></label>
        <button type="submit">Look up</button>
    </form>
    <div class="p3a-status-result" data-bind="status-result" hidden></div>
    <p><a href="<?= e(url('submit')) ?>">Submit a new request</a></p>
</section>
