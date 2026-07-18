<?php
declare(strict_types=1);
/**
 * Generic error page (e.g. 404). No internal detail is ever shown (§10.10).
 *
 * @var int    $code
 * @var string $message
 */
$title = ($code ?? 'Error') . ' — P3A Support';
?>
<section class="p3a-error">
    <h1><?= e((string) ($code ?? 'Error')) ?></h1>
    <p><?= e($message ?? 'Something went wrong.') ?></p>
    <p><a href="<?= e(url('')) ?>">Return home</a></p>
</section>
