<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Security\Audit;
use App\Security\MessageVisibility;
use App\Security\Rbac;
use App\Security\Upload;
use App\Core\Config;

/**
 * UploadController (§10.7) — attachment upload and download. These are NOT /api JSON
 * routes (they carry multipart / binary), so they run the same gates explicitly:
 * authentication, session CSRF, and ownership.
 *
 * Downloads are streamed with Content-Disposition: attachment, X-Content-Type-Options:
 * nosniff, and Content-Type application/octet-stream for everything except a small map
 * of known-safe image types — the stored MIME is NEVER echoed back verbatim (that is
 * how an uploaded text/html would become stored XSS on our own origin).
 */
final class UploadController
{
    /** Content types we are willing to serve with their real type (still as attachment). */
    private const SAFE_INLINE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    public function upload(Request $request): Response
    {
        if (Session::current() === null) {
            return Response::json(['ok' => false, 'error' => 'Authentication required.'], 401);
        }
        if (!Csrf::validate($request->str('csrf'))) {
            Logger::security('upload_csrf_fail');
            return Response::json(['ok' => false, 'error' => 'Invalid CSRF token.'], 419);
        }

        $ticketId = $request->str('ticket_id');
        if (!Rbac::ownsTicket($ticketId)) {
            Logger::security('upload_authz_denied', "ticket={$ticketId}");
            return Response::json(['ok' => false, 'error' => 'You are not allowed to attach to this ticket.'], 403);
        }
        if (Ticket::find($ticketId) === null) {
            return Response::json(['ok' => false, 'error' => 'Ticket not found.'], 404);
        }

        $max = Config::int('UPLOAD_MAX_PER_TICKET', 20);
        if (Attachment::countForTicket($ticketId) >= $max) {
            return Response::json(['ok' => false, 'error' => "This ticket already has the maximum {$max} attachments."], 422);
        }

        $file = $request->files()['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return Response::json(['ok' => false, 'error' => 'No file was uploaded.'], 422);
        }
        // Defence: the temp path MUST be a genuine PHP upload, never an arbitrary path.
        if (!is_uploaded_file((string) $file['tmp_name'])) {
            Logger::security('upload_not_uploaded_file');
            return Response::json(['ok' => false, 'error' => 'Invalid upload.'], 400);
        }

        $result = Upload::process((string) $file['name'], (string) $file['tmp_name']);
        if (($result['ok'] ?? false) !== true) {
            Logger::security('upload_rejected', (string) ($result['error'] ?? ''));
            return Response::json(['ok' => false, 'error' => (string) $result['error']], 422);
        }

        // Staff may mark an attachment internal; customer uploads are never internal.
        $isInternal = Rbac::isAtLeastAgent() && $request->str('internal') === '1';

        $id = Attachment::create([
            'ticket_id'     => $ticketId,
            'message_id'    => null,
            'original_name' => $result['original_name'],
            'stored_name'   => $result['stored_name'],
            'mime_type'     => $result['mime_type'],
            'size_bytes'    => $result['size_bytes'],
            'sha256'        => $result['sha256'],
            'is_internal'   => $isInternal,
            'uploaded_by'   => (string) Session::email(),
        ]);
        Audit::log((string) Session::email(), 'attachment_upload', $ticketId, $result['original_name']);

        return Response::json(['ok' => true, 'data' => [
            'id'            => $id,
            'original_name' => $result['original_name'],
            'size_bytes'    => $result['size_bytes'],
            'is_internal'   => $isInternal,
        ]]);
    }

    public function download(Request $request, string $rest): Response
    {
        if (Session::current() === null) {
            return redirect('login');
        }
        $id = (int) $rest;
        $att = Attachment::find($id);
        // Ownership + internal visibility. Return 404 (not 403) so we never confirm
        // the existence of a file the viewer isn't entitled to see.
        if ($att === null || !Rbac::ownsTicket((string) $att['ticket_id'])) {
            return Response::make('Not found', 404, 'text/plain; charset=utf-8');
        }
        if ((int) $att['is_internal'] === 1 && !MessageVisibility::seesInternal((string) Session::role())) {
            return Response::make('Not found', 404, 'text/plain; charset=utf-8');
        }

        $path = Upload::storageDir() . '/' . $att['stored_name'];
        if (!is_file($path)) {
            return Response::make('Not found', 404, 'text/plain; charset=utf-8');
        }

        $stored = (string) $att['mime_type'];
        $contentType = in_array($stored, self::SAFE_INLINE_TYPES, true) ? $stored : 'application/octet-stream';
        $filename = Upload::sanitizeName((string) $att['original_name']);

        return Response::make((string) file_get_contents($path), 200, $contentType)
            ->withHeader('Content-Disposition', 'attachment; filename="' . addslashes($filename) . '"')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Length', (string) filesize($path));
    }
}
