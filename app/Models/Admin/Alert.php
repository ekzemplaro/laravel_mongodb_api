<?php
namespace App\Models\Admin;

use App\Models\Alert as AlertModel;

class Alert extends AlertModel
{
    protected $appends = [
        'topic',
    ];

    public function getTopicAttribute()
    {
        return Topic::find($this->topic_id);
    }
}
