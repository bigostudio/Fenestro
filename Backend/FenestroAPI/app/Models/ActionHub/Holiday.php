<?php

namespace App\Models\ActionHub;

use Illuminate\Database\Eloquent\Model;

/** A non-working day for the Action Hub working-day rule (admin-managed). */
class Holiday extends Model
{
    protected $table = 'action_hub_holidays';

    protected $fillable = ['date', 'name', 'created_by'];

    protected $casts = ['date' => 'date:Y-m-d'];
}
