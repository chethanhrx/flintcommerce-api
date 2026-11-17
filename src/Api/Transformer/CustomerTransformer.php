<?php

declare(strict_types=1);

namespace App\Api\Transformer;

use App\Domain\Model\Customer;

final class CustomerTransformer
{
    public function __construct(
        private readonly string $baseUrl,
    ) {
    }

    /**


     * @return array<string, mixed>


     */


    public function transform(Customer $customer): array
    {
        $customerUrl = $this->baseUrl . "/api/fc/v3/customers/{$customer->id}";

        return [
            'id' => (int) $customer->id,
            'date_created' => $this->formatDate($customer->created_at),
            'date_created_gmt' => $this->formatDate($customer->created_at),
            'date_modified' => $this->formatDate($customer->updated_at),
            'date_modified_gmt' => $this->formatDate($customer->updated_at),
            'email' => (string) $customer->email,
            'first_name' => (string) $customer->first_name,
            'last_name' => (string) $customer->last_name,
            'role' => (string) $customer->role,
            'username' => (string) $customer->username,
            'billing' => [], // populated by controller if addresses loaded
            'shipping' => [], // populated by controller if addresses loaded
            'is_paying_customer' => false,
            'avatar_url' => (string) $customer->avatar_url,
            'is_active' => (bool) $customer->is_active,
            'last_login_at' => $this->formatDate($customer->last_login_at),
        ];
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $dt = new \DateTimeImmutable($value);
            return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s');
        } catch (\Exception) {
            return null;
        }
    }
}