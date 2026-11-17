<?php

declare(strict_types=1);

namespace App\Api\Transformer;

use App\Domain\Model\ProductReview;

final class ProductReviewTransformer
{
    public function __construct(
        private readonly string $baseUrl,
    ) {
    }

    /**


     * @return array<string, mixed>


     */


    public function transform(ProductReview $review): array
    {
        $reviewUrl = $this->baseUrl . "/api/fc/v3/products/{$review->product_id}/reviews/{$review->id}";

        return [
            'id' => (int) $review->id,
            'product_id' => (int) $review->product_id,
            'status' => (string) $review->status,
            'reviewer' => (string) $review->reviewer_name,
            'reviewer_email' => (string) $review->reviewer_email,
            'reviewer_email_md5' => (string) $review->reviewer_email_md5,
            'rating' => (int) $review->rating,
            'review' => (string) $review->review,
            'verified' => (bool) $review->verified,
            'date_created' => $this->formatDate($review->created_at),
            'date_created_gmt' => $this->formatDate($review->created_at),
            'date_modified' => $this->formatDate($review->updated_at),
            'date_modified_gmt' => $this->formatDate($review->updated_at),
            '_links' => [
                'self' => [
                    [
                        'href' => $reviewUrl,
                    ],
                ],
                'collection' => [
                    [
                        'href' => $this->baseUrl . "/api/fc/v3/products/{$review->product_id}/reviews",
                    ],
                ],
                'product' => [
                    [
                        'href' => $this->baseUrl . "/api/fc/v3/products/{$review->product_id}",
                    ],
                ],
            ],
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