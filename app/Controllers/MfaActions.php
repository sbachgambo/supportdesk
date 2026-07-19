<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Session;
use App\Core\ValidationException;
use App\Services\MfaService;

/**
 * MfaActions (D8) — TOTP enrolment + challenge. getMfaStatus/enrollTotp/confirmTotp/
 * verifyMfa are reachable while a session is unverified (they ARE how it becomes
 * verified); disableTotp/regenerateBackupCodes require a verified session.
 */
final class MfaActions
{
    private function userId(): int
    {
        return (int) Session::userId();
    }

    public function getMfaStatus(array $payload, Request $request): array
    {
        return MfaService::status($this->userId());
    }

    public function enrollTotp(array $payload, Request $request): array
    {
        $user = \App\Models\User::findById($this->userId());
        if ($user === null || (string) $user['role'] === 'customer') {
            throw new ValidationException('MFA is not available for this account.');
        }
        return MfaService::beginEnrollment($this->userId(), (string) Session::email());
    }

    public function confirmTotp(array $payload, Request $request): array
    {
        $result = MfaService::confirmEnrollment($this->userId(), (string) ($payload['code'] ?? ''));
        if (($result['ok'] ?? false) !== true) {
            throw new ValidationException((string) $result['error']);
        }
        return ['ok' => true, 'backup_codes' => $result['backup_codes']];
    }

    public function verifyMfa(array $payload, Request $request): array
    {
        $result = MfaService::verifyChallenge($this->userId(), (string) ($payload['code'] ?? ''));
        if (($result['ok'] ?? false) !== true) {
            throw new ValidationException((string) $result['error']);
        }
        return ['ok' => true, 'backup_used' => $result['backup_used'] ?? false];
    }

    public function disableTotp(array $payload, Request $request): array
    {
        $result = MfaService::disable($this->userId());
        if (($result['ok'] ?? false) !== true) {
            throw new ValidationException((string) $result['error']);
        }
        return ['ok' => true];
    }
}
