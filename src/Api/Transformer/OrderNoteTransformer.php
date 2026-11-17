<?php

declare(strict_types=1);

namespace App\Api\Transformer;

use App\Domain\Model\OrderNote;

final class OrderNoteTransformer
{
    public function __construct(
        private readonly string $baseUrl,
    ) {
    }

    /**


     * @return array<string, mixed>


     */


    public function transform(OrderNote $note): array
    {
        $noteUrl = $this->baseUrl . "/api/fc/v3/orders/{$note->order_id}/notes/{$note->id}";

        return [
            'id' => (int) $note->id,
            'date_created' => $this->formatDate($note->date_created),
            'date_created_gmt' => $this->formatDate($note->date_created_gmt),
            'note' => (string) $note->note,
            'customer_note' => (bool) $note->customer_note,
            'added_by_user_id' => $note->added_by_user_id !== null ? (int) $note->added_by_user_id : null,
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