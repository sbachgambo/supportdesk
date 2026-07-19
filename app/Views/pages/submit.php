<?php
declare(strict_types=1);
/**
 * Public submit form (Phase 9). Posts to /api submitTicket via public.js with the
 * stateless HMAC CSRF token (D6). Includes a honeypot ('website') hidden field.
 * Works standalone and inside the widget iframe.
 *
 * @var string $csrf @var array $categories @var bool $widget
 */
$widget = $widget ?? false;
?>
<section class="p3a-public-form" data-form="submit" data-csrf="<?= e($csrf) ?>">
    <h1>Submit a request</h1>
    <p class="p3a-form-msg" data-bind="submit-msg" role="status"></p>
    <form data-action="submit-ticket" autocomplete="on">
        <label>Your name<input type="text" name="customer_name" maxlength="120"></label>
        <label>Email<input type="email" name="customer_email" required maxlength="254"></label>
        <label>Subject<input type="text" name="subject" required maxlength="200"></label>
        <label>Category
            <select name="category_id">
                <option value="">— none —</option>
                <?php foreach (($categories ?? []) as $c): ?>
                    <option value="<?= e($c['category_id']) ?>"><?= e(($c['parent_id'] ? '— ' : '') . $c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Priority
            <select name="priority">
                <option value="low">Low</option>
                <option value="normal" selected>Normal</option>
                <option value="high">High</option>
                <?php /* 'urgent' is deliberately NOT offered to the public (priority ceiling, §3) */ ?>
            </select>
        </label>
        <label>Description<textarea name="description" required maxlength="5000" rows="5"></textarea></label>
        <?php // Honeypot: real users never see or fill this; bots do. ?>
        <div class="p3a-hp" aria-hidden="true">
            <label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
        </div>
        <button type="submit">Submit request</button>
    </form>
    <?php if (!$widget): ?>
        <p><a href="<?= e(url('status')) ?>">Check an existing ticket</a></p>
    <?php endif; ?>
</section>
