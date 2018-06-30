<?php

namespace App\Models\Admin;

use App\Models\PickedUpTopic as PickedUpTopicModel;

class PickedUpTopic extends PickedUpTopicModel
{
    protected $appends = ['topic'];

    public function getTopicAttribute()
    {
        return Topic::find($this->topic_id);
    }
}
