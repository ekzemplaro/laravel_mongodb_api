<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Validator;
use App\Models\Api\Alert;
use App\Models\Api\Topic;
use App\Models\Api\User;
use App\Models\Api\Category;
use Illuminate\Validation\Rule;
use Log;
use Exception;

class AlertService
{
    public static function getActivatedOption()
    {
        return [
            config('alert.activated') => trans('admin/alerts.status.activated'),
            config('alert.not_activate') => trans('admin/alerts.status.not_activate'),
        ];
    }
}
