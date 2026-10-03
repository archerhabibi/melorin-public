<?php

namespace App\Channels\Website\Support;

use Illuminate\Support\Facades\Http;

/**
 * OIDC Authorization Code + PKCE (S256) برای Google Sign-In — بدون Socialite.
 * GOOGLE-SIGNIN-CONTRACT.md §G12.
 *
 * این کلاس فقط «پروتکل» است؛ هیچ User/Session/Audit ای نمی‌سازد (آن‌ها: کنترلر + ExternalIdentityService).
 *
 * اعتبارسنجی id_token: توکن مستقیماً از Token Endpoint گوگل روی TLS و با client_secret + PKCE
 * گرفته می‌شود (نه از Browser)، پس طبق مستندات OpenID Connect گوگل امضای JWT اجباری نیست؛ ولی
 * همه‌ی Claimها بررسی می‌شوند: iss، aud، exp، iat، nonce، sub، email، email_verified.
 */
class GoogleOAuthClient
{
    public const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    public function enabled(): bool
    {
        return (bool) config('services.google.enabled')
            && filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'));
    }

    public function redirectUri(): string
    {
        return (string) (config('services.google.redirect') ?: route('auth.google.callback'));
    }

    public function authorizationUrl(string $state, string $nonce, string $codeVerifier): string
    {
        return self::AUTH_ENDPOINT.'?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => self::codeChallenge($codeVerifier),
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function codeChallenge(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }

    /**
     * @throws GoogleOAuthException
     */
    public function fetchProfile(string $code, string $codeVerifier, string $expectedNonce): GoogleProfile
    {
        try {
            $response = Http::asForm()
                ->timeout(10)
                ->post(self::TOKEN_ENDPOINT, [
                    'code' => $code,
                    'client_id' => config('services.google.client_id'),
                    'client_secret' => config('services.google.client_secret'),
                    'redirect_uri' => $this->redirectUri(),
                    'grant_type' => 'authorization_code',
                    'code_verifier' => $codeVerifier,
                ]);
        } catch (\Throwable $e) {
            throw new GoogleOAuthException('token_request_failed: '.$e::class);
        }

        if (! $response->successful()) {
            throw new GoogleOAuthException('token_http_'.$response->status());
        }

        $idToken = $response->json('id_token');

        if (! is_string($idToken) || $idToken === '') {
            throw new GoogleOAuthException('id_token_missing');
        }

        return $this->validateIdToken($idToken, $expectedNonce);
    }

    /**
     * @throws GoogleOAuthException
     */
    public function validateIdToken(string $idToken, string $expectedNonce): GoogleProfile
    {
        $parts = explode('.', $idToken);

        if (count($parts) !== 3) {
            throw new GoogleOAuthException('id_token_malformed');
        }

        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $claims = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($claims)) {
            throw new GoogleOAuthException('id_token_payload_invalid');
        }

        if (! in_array($claims['iss'] ?? null, ['https://accounts.google.com', 'accounts.google.com'], true)) {
            throw new GoogleOAuthException('iss_mismatch');
        }

        $aud = $claims['aud'] ?? null;
        $clientId = (string) config('services.google.client_id');

        if (! (is_string($aud) ? hash_equals($clientId, $aud) : (is_array($aud) && in_array($clientId, $aud, true)))) {
            throw new GoogleOAuthException('aud_mismatch');
        }

        $now = time();

        if (! is_numeric($claims['exp'] ?? null) || (int) $claims['exp'] < $now - 60) {
            throw new GoogleOAuthException('token_expired');
        }

        if (isset($claims['iat']) && is_numeric($claims['iat']) && (int) $claims['iat'] > $now + 300) {
            throw new GoogleOAuthException('iat_in_future');
        }

        if (! is_string($claims['nonce'] ?? null) || ! hash_equals($expectedNonce, $claims['nonce'])) {
            throw new GoogleOAuthException('nonce_mismatch');
        }

        $sub = $claims['sub'] ?? null;

        if (! is_string($sub) || $sub === '' || strlen($sub) > 191) {
            throw new GoogleOAuthException('sub_invalid');
        }

        $email = $claims['email'] ?? null;

        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new GoogleOAuthException('email_invalid');
        }

        // گوگل گاهی بولین و گاهی رشته‌ی "true" می‌فرستد.
        $verified = $claims['email_verified'] ?? false;
        $verified = $verified === true || $verified === 'true';

        $name = isset($claims['name']) && is_string($claims['name']) ? mb_substr($claims['name'], 0, 120) : null;

        return new GoogleProfile($sub, $email, $verified, $name);
    }
}
