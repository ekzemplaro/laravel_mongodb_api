<?php

namespace App\Models\Admin;

use App\Models\Region as RegionModel;

class Region extends RegionModel
{
    protected $appends = [
        'prefectures'
    ];

    public function getPrefecturesAttribute()
    {
        return Prefecture::where('flag_index', 1)
            ->where('created_at', '>', 0)
            ->withIndex('order_by_created_at_index')
            ->where('region_id', $this->id)
            ->get();
    }
}
