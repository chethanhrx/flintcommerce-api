<?php

declare(strict_types=1);

namespace App\Domain\Model;

use FlintPHP\Framework\Orm\Model;

final class ProductReview extends Model
{
    protected string $table = 'product_reviews';
    protected string $primaryKey = 'id';

    /** @var array<int, string> */
    protected array $fillable = [
        'product_id', 'reviewer_name', 'reviewer_email', 'reviewer_email_md5', 'status',
        'rating', 'review', 'verified', 'created_at', 'updated_at',
    ];

    public $id;
    public $product_id;
    public $reviewer_name;
    public $reviewer_email;
    public $reviewer_email_md5;
    public $status;
    public $rating;
    public $review;
    public $verified;
    public $created_at;
    public $updated_at;
}