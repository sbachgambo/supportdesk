<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Security\Auth;
use App\Services\PasswordReset;

/**
 * AuthController — login / logout / password-reset HTTP flows (§9, §10.3, §10.9).
 *
 * These are direct page routes (not the /api gateway). Because the forms are shown
 * to anonymous users, they carry the STATELESS public CSRF token (D6); logout is
 * from an authenticated session and uses the session-bound token.
 *
 * Passwords are never trimmed (NIST allows leading/trailing spaces, §10.3).
 */
final class AuthController
{
    private const RESET_COOKIE = 'p3a_reset';

    public function showLogin(Request $request): Response
    {
        if (Session::current() !== null) {
            return redirect('dashboard');
        }
        $company = \App\Models\AppConfig::get('company_name', 'SupportDesk');
        return Response::html(View::render('auth/login', [
            'title'   => 'Sign in — ' . $company,
            'csrf'    => Csrf::publicToken('login'),
            'error'   => null,
            'email'   => '',
            'company' => $company,
        ], 'bare'));
    }

    public function login(Request $request): Response
    {
        if (!Csrf::validatePublic($request->str('csrf'), 'login')) {
            return $this->loginError($request, 'Your session expired. Please try again.');
        }

        $email = $request->str('email');
        $password = (string) $request->input('password', '');

        $result = Auth::attempt($email, $password, $request->ip(), $request->userAgent());
        if (!$result['success']) {
            // Auth already returns a single generic error (enumeration parity, §10.3).
            return $this->loginError($request, (string) $result['error']);
        }

        return $result['mfa_required'] ? redirect('mfa') : redirect('dashboard');
    }

    public function logout(Request $request): Response
    {
        // Session-bound CSRF for an authenticated action (§10.8).
        if (Session::current() !== null && Csrf::validate($request->str('csrf'))) {
            Auth::logout();
        }
        return redirect('login');
    }

    // ── Password reset ───────────────────────────────────────────────────────
    public function showForgot(Request $request): Response
    {
        return Response::html(View::render('auth/forgot', [
            'title'   => 'Reset your password — P3A Support',
            'csrf'    => Csrf::publicToken('forgot'),
            'company' => $this->company(),
            'notice'  => null,
        ], 'bare'));
    }

    public function forgot(Request $request): Response
    {
        if (Csrf::validatePublic($request->str('csrf'), 'forgot')) {
            PasswordReset::request($request->str('email'), $request->ip());
        }
        // Enumeration-safe: the same notice regardless of whether the email exists.
        return Response::html(View::render('auth/forgot', [
            'title'   => 'Reset your password — P3A Support',
            'csrf'    => Csrf::publicToken('forgot'),
            'company' => $this->company(),
            'notice'  => 'If that email belongs to an active account, a reset link is on its way.',
        ], 'bare'));
    }

    /**
     * GET /reset — two modes:
     *   ?token=X present → consume the token, set the tokenless reset cookie, and
     *                      302 to /reset with NO token in the URL (§10.9).
     *   no token         → show the new-password form if the reset cookie is valid.
     */
    public function showReset(Request $request): Response
    {
        $token = $request->query('token');
        if ($token !== null && $token !== '') {
            $email = PasswordReset::consumeToken($token);
            if ($email === null) {
                return $this->resetInvalid();
            }
            // Rewrite the URL tokenless; hand over a short-lived signed reset cookie.
            $this->setResetCookie(PasswordReset::makeResetCookie($email));
            return redirect('reset'); // 302 to /reset — no token, nothing in history/Referer
        }

        $email = PasswordReset::readResetCookie($request->cookie(self::RESET_COOKIE));
        if ($email === null) {
            return $this->resetInvalid();
        }
        return Response::html(View::render('auth/reset', [
            'title'   => 'Choose a new password — P3A Support',
            'csrf'    => Csrf::publicToken('reset'),
            'company' => $this->company(),
            'error'   => null,
        ], 'bare'));
    }

    public function reset(Request $request): Response
    {
        $email = PasswordReset::readResetCookie($request->cookie(self::RESET_COOKIE));
        if ($email === null || !Csrf::validatePublic($request->str('csrf'), 'reset')) {
            return $this->resetInvalid();
        }

        $password = (string) $request->input('password', '');
        $error = PasswordReset::complete($email, $password, $request->ip());
        if ($error !== null) {
            return Response::html(View::render('auth/reset', [
                'title'   => 'Choose a new password — P3A Support',
                'csrf'    => Csrf::publicToken('reset'),
                'company' => $this->company(),
                'error'   => $error,
            ], 'bare'));
        }

        $this->clearResetCookie();
        return Response::html(View::render('auth/reset_done', [
            'title'   => 'Password updated — P3A Support',
            'company' => $this->company(),
        ], 'bare'));
    }

    // ── helpers ──────────────────────────────────────────────────────────────
    private function loginError(Request $request, string $message): Response
    {
        $company = \App\Models\AppConfig::get('company_name', 'SupportDesk');
        return Response::html(View::render('auth/login', [
            'title'   => 'Sign in — ' . $company,
            'csrf'    => Csrf::publicToken('login'),
            'error'   => $message,
            'email'   => $request->str('email'),
            'company' => $company,
        ], 'bare'), 401);
    }

    private function resetInvalid(): Response
    {
        return Response::html(View::render('auth/reset_invalid', [
            'title'   => 'Reset link invalid — P3A Support',
            'company' => $this->company(),
        ], 'bare'), 400);
    }

    private function company(): string
    {
        return \App\Models\AppConfig::get('company_name', 'SupportDesk');
    }

    private function setResetCookie(string $value): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie(self::RESET_COOKIE, $value, [
            'expires'  => time() + 900,
            'path'     => '/reset',
            'httponly' => true,
            'secure'   => \App\Core\Config::isProduction(),
            'samesite' => 'Lax',
        ]);
    }

    private function clearResetCookie(): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie(self::RESET_COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => '/reset',
            'httponly' => true,
            'secure'   => \App\Core\Config::isProduction(),
            'samesite' => 'Lax',
        ]);
    }
}
