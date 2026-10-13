<?php

namespace App\Models;

use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** One security-rating capture for a vendor. Strictly per bank (the table had no organization_id). */
class TprmSecurityRating extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected $casts = ['captured_at' => 'datetime', 'rating_value' => 'integer', 'delta_from_previous' => 'integer'];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public static function gradeFor(int $value): string
    {
        foreach (config('tprm.grades') as $grade => $floor) {
            if ($value >= $floor) {
                return $grade;
            }
        }

        return 'F';
    }
}
