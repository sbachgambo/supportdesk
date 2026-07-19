<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Session;
use App\Core\ValidationException;
use App\Models\KbArticle;
use App\Security\Audit;

/**
 * KbActions (§3) — knowledge base. Agents publish/edit; DELETE IS ADMIN-ONLY (enforced
 * by the gateway REQUIRES map). Articles are public|internal; view counts increment on
 * read. A separate public action serves only public articles to anonymous visitors.
 */
final class KbActions
{
    private const VISIBILITIES = ['public', 'internal'];

    public function getKbArticles(array $payload, Request $request): array
    {
        $search = trim((string) ($payload['search'] ?? ''));
        $category = trim((string) ($payload['category'] ?? ''));
        return [
            'articles'   => KbArticle::listForStaff($search, $category),
            'categories' => KbArticle::categories(),
        ];
    }

    /** Public: only visibility = public articles (no internal ever). */
    public function getPublicKb(array $payload, Request $request): array
    {
        $search = trim((string) ($payload['search'] ?? ''));
        if (!empty($payload['article_id'])) {
            $article = KbArticle::find((string) $payload['article_id']);
            if ($article === null || $article['visibility'] !== 'public') {
                throw new ValidationException('Article not found.');
            }
            KbArticle::incrementViews((string) $article['article_id']);
            return ['article' => ['title' => $article['title'], 'category' => $article['category'], 'body' => $article['body']]];
        }
        return ['articles' => KbArticle::listPublic($search)];
    }

    public function getKbArticle(array $payload, Request $request): array
    {
        $article = KbArticle::find((string) ($payload['article_id'] ?? ''));
        if ($article === null) {
            throw new ValidationException('Article not found.');
        }
        KbArticle::incrementViews((string) $article['article_id']);
        return ['article' => $article];
    }

    public function publishKbArticle(array $payload, Request $request): array
    {
        [$title, $body, $visibility, $category] = $this->validated($payload);
        $id = KbArticle::create([
            'title' => $title, 'body' => $body, 'visibility' => $visibility,
            'category' => $category, 'author' => (string) Session::email(),
        ]);
        Audit::log((string) Session::email(), 'kb_publish', $id, $title);
        return ['article_id' => $id];
    }

    public function editKbArticle(array $payload, Request $request): array
    {
        $id = (string) ($payload['article_id'] ?? '');
        if (KbArticle::find($id) === null) {
            throw new ValidationException('Article not found.');
        }
        [$title, $body, $visibility, $category] = $this->validated($payload);
        KbArticle::update($id, ['title' => $title, 'body' => $body, 'visibility' => $visibility, 'category' => $category]);
        Audit::log((string) Session::email(), 'kb_edit', $id);
        return ['ok' => true];
    }

    /** Admin-only (gateway REQUIRES 'admin'). */
    public function deleteKbArticle(array $payload, Request $request): array
    {
        $id = (string) ($payload['article_id'] ?? '');
        if (KbArticle::find($id) === null) {
            throw new ValidationException('Article not found.');
        }
        KbArticle::delete($id);
        Audit::log((string) Session::email(), 'kb_delete', $id);
        return ['ok' => true];
    }

    /** @return array{0:string,1:string,2:string,3:string} */
    private function validated(array $payload): array
    {
        $title = trim((string) ($payload['title'] ?? ''));
        $body = trim((string) ($payload['body'] ?? ''));
        $visibility = (string) ($payload['visibility'] ?? 'internal');
        $category = mb_substr(trim((string) ($payload['category'] ?? '')), 0, 60);
        if ($title === '' || mb_strlen($title) > 150) {
            throw new ValidationException('Title is required (max 150 chars).');
        }
        if ($body === '') {
            throw new ValidationException('Article body is required.');
        }
        if (!in_array($visibility, self::VISIBILITIES, true)) {
            throw new ValidationException('Invalid visibility.');
        }
        return [$title, $body, $visibility, $category];
    }
}
