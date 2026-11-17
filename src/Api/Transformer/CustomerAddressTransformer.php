<?php

declare(strict_types=1);

namespace App\Api\Transformer;

use App\Domain\Model\CustomerAddress;

final class CustomerAddressTransformer
{
    public function __construct(
        private readonly string $baseUrl,
    ) {
    }

    /**


     * @return array<string, mixed>


     */


    public function transform(CustomerAddress $address): array
    {
        return [
            'id' => (int) $address->id,
            'first_name' => (string) $address->first_name,
            'last_name' => (string) $address->last_name,
            'company' => (string) $address->company,
            'address_1' => (string) $address->address_1,
            'address_2' => (string) $address->address_2,
            'city' => (string) $address->city,
            'state' => (string) $address->state,
            'postcode' => (string) $address->postcode,
            'country' => (string) $address->country,
            'phone' => (string) $address->phone,
        ];
    }
}