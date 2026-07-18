<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * View — renders a PHP template from app/Views/pages/ inside an optional layout.
 * Templates escape by default via the e() helper; raw output requires raw() with
 * an adjacent justification (§10.2). Data is passed explicitly, never extracted
 * from a superglobal.
 */
final class View
{
    private static function base(): string
    {
        return (defined('P3A_ROOT') ? P3A_ROOT : dirname(__DIR__, 2)) . '/app/Views';
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function render(string $page, array $data = [], ?string $layout = 'main'): string
    {
        $content = self::renderFile("pages/{$page}.php", $data);

        if ($layout === null) {
            return $content;
        }
        return self::renderFile("layouts/{$layout}.php", $data + ['content' => $content]);
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function renderFile(string $relative, array $data): string
    {
        $file = self::base() . '/' . $relative;
        if (!is_file($file)) {
            throw new RuntimeException("View: template not found: {$relative}");
        }

        // Templates reference $data['key'] or the extracted $key; keep it explicit.
        $render = static function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            include $__file;
            return (string) ob_get_clean();
        };

        return $render($file, $data);
    }
}
