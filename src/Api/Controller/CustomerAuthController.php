<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Api\Exception\WcApiException;
use App\Domain\Repository\CustomerRepository;
use App\Security\JwtAuth;
use FlintPHP\Framework\Http\Request;
use FlintPHP\Framework\Http\Response;

final class CustomerAuthController
{
    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly JwtAuth $jwt,
    ) {
    }

    public function login(Request $request): Response
    {
        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        $missing = [];
        if (!isset($payload['username']) || !is_string($payload['username']) || trim($payload['username']) === '') {
            $missing['username'] = 'username or email is required';
        }
        if (!isset($payload['password']) || !is_string($payload['password'])) {
            $missing['password'] = 'password is required';
        }

        if ($missing !== []) {
            throw WcApiException::missingParam($missing);
        }

        $username = trim($payload['username']);
        $password = $payload['password'];

        $customer = $this->customers->findByEmail($username);
        if ($customer === null) {
            $customer = $this->customers->findByUsername($username);
        }

        if ($customer === null || !$this->customers->verifyPassword($customer, $payload['password'])) {
            throw new WcApiException(
                'woocommerce_rest_auth_invalid_credentials',
                'Invalid credentials.',
                401,
            );
        }

        if ((int) $customer->is_active === 0) {
            throw new WcApiException(
                'woocommerce_rest_auth_account_locked',
                'Your account has been deactivated.',
                403,
            );
        }

        $this->customers->updateLastLogin($customer->id);

        $token = $this->jwt->issue($customer->id);

        return Response::json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => 86400,
            'customer_id' => $customer->id,
        ], 200);
    }

    public function register(Request $request): Response
    {
        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        $missing = [];
        if (!isset($payload['email']) || !is_string($payload['email']) || !filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
            $missing['email'] = 'A valid email address is required.';
        }
        if (!isset($payload['password']) || !is_string($payload['password']) || strlen($payload['password']) < 8) {
            $missing['password'] = 'Password must be at least 8 characters.';
        }
        if (!isset($payload['first_name']) || !is_string($payload['first_name']) || trim($payload['first_name']) === '') {
            $missing['first_name'] = 'first_name is required.';
        }
        if (!isset($payload['last_name']) || !is_string($payload['last_name']) || trim($payload['last_name']) === '') {
            $missing['last_name'] = 'last_name is required.';
        }

        if ($missing !== []) {
            throw WcApiException::missingParam($missing);
        }

        // Check if email exists
        if ($this->customers->findByEmail($payload['email']) !== null) {
            throw new WcApiException(
                'woocommerce_rest_registration_error_email_exists',
                'An account with that email already exists.',
                400,
            );
        }

        $now = gmdate('Y-m-d H:i:s');
        $passwordHash = password_hash($payload['password'], PASSWORD_BCRYPT);

        $db = $this->customers->getConnection();

        $db->execute(
            'INSERT INTO customers (email, password_hash, first_name, last_name, username, role, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, \'customer\', 1, ?, ?)',
            [
                $payload['email'],
                password_hash($payload['password'], PASSWORD_BCRYPT),
                $payload['first_name'],
                $payload['last_name'],
                $payload['username'] ?? $payload['email'],
                gmdate('Y-m-d H:i:s'),
                gmdate('Y-m-d H:i:s'),
            ],
        );

        $customerId = (int) $db->fetchColumn('SELECT id FROM customers WHERE email = ?', [$payload['email']]);
        $token = $this->jwt->issue($customerId);

        return Response::json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => 86400,
            'customer_id' => $customerId,
        ], 201);
    }

    public function refresh(Request $request): Response
    {
        $payload = $request->json();
        $payload = is_array($payload) ? $payload : [];

        if (!isset($payload['refresh_token']) || !is_string($payload['refresh_token'])) {
            throw WcApiException::missingParam(['refresh_token' => 'refresh_token is required']);
        }

        $newToken = $this->jwt->refresh($payload['refresh_token']);

        if ($newToken === null) {
            throw new WcApiException(
                'woocommerce_rest_auth_invalid_token',
                'Invalid or expired refresh token.',
                401,
            );
        }

        return Response::json([
            'access_token' => $newToken,
            'token_type' => 'Bearer',
            'expires_in' => 86400,
        ], 200);
    }

    public function me(Request $request): Response
    {
        // This would be protected by JWT middleware
        // For now, return a placeholder
        return Response::json([
            'message' => 'Use Bearer token to access customer data',
        ], 200);
    }
}