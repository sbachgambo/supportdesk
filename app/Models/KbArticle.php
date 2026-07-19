<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

/**
 * KbArticle (Model) — thin repository over knowledge_base. Visibility is public|internal:
 * staff see both, the public sees only public. Search is a bound LIKE over title+body
 * (the FULLTEXT index ft_kb backs this at scale). view_count increments on read.
 */
final class KbArticle
{
    /** Staff list (both visibilities) with optional search + category filter, fully bound. */
    public static function listForStaff(string $search = '', string $category = ''): array
    {
        $like = '%' . $search . '%';
        return Db::queryAll(
            "SELECT article_id, title, category, visibility, author, view_count, updated_at
             FROM knowledge_base
             WHERE (:s = '' OR title LIKE :like1 OR body LIKE :like2)
               AND (:cat = '' OR category = :cat2)
             ORDER BY updated_at DESC",
            [':s' => $search, ':like1' => $like, ':like2' => $like, ':cat' => $category, ':cat2' => $category]
        );
    }

    /** Public list — visibility = public only. */
    public static function listPublic(string $search = ''): array
    {
        $like = '%' . $search . '%';
        return Db::queryAll(
            "SELECT article_id, title, category, view_count, updated_at
             FROM knowledge_base
             WHERE visibility = 'public'
               AND (:s = '' OR title LIKE :like1 OR body LIKE :like2)
             ORDER BY updated_at DESC",
            [':s' => $search, ':like1' => $like, ':like2' => $like]
        );
    }

    public static function find(string $articleId): ?array
    {
        return Db::queryOne('SELECT * FROM knowledge_base WHERE article_id = :a', [':a' => $articleId]);
    }

    public static function incrementViews(string $articleId): void
    {
        Db::query('UPDATE knowledge_base SET view_count = view_count + 1 WHERE article_id = :a', [':a' => $articleId]);
    }

    /** Distinct category chips. */
    public static function categories(): array
    {
        $rows = Db::queryAll("SELECT DISTINCT category FROM knowledge_base WHERE category <> '' ORDER BY category");
        return array_map(static fn($r) => (string) $r['category'], $rows);
    }

    public static function create(array $data): string
    {
        $id = self::nextArticleId();
        Db::insert('knowledge_base', [
            'article_id' => $id,
            'title'      => $data['title'],
            'category'   => $data['category'] ?? '',
            'body'       => $data['body'],
            'visibility' => $data['visibility'] ?? 'internal',
            'author'     => $data['author'],
            'view_count' => 0,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return $id;
    }

    /** @param array<string,mixed> $fields */
    public static function update(string $articleId, array $fields): void
    {
        $fields['updated_at'] = gmdate('Y-m-d H:i:s');
        Db::update('knowledge_base', $fields, 'article_id = :a', [':a' => $articleId]);
    }

    public static function delete(string $articleId): void
    {
        Db::delete('knowledge_base', 'article_id = :a', [':a' => $articleId]);
    }

    public static function nextArticleId(): string
    {
        $max = Db::scalar("SELECT MAX(CAST(SUBSTRING_INDEX(article_id, :dash, -1) AS UNSIGNED)) FROM knowledge_base", [':dash' => '-']);
        return sprintf('KB-%04d', ((int) $max) + 1);
    }
}
