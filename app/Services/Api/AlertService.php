<?php

namespace App\Services\Api;

use App\Models\Api\Topic;
use App\Models\Api\Alert;

class AlertService
{

    public static function store($input)
    {
        $alert = Alert::where('topic_id', $input['topic_id'])
            ->where('user_id', $input['user_id'])
            ->first();

        $topic = Topic::where('id', $input['topic_id'])->where('activated', config('topic.activated'))->first();

        if ($topic) {
            $arrReport = $topic->report ?? [];
            $arrReport[$input['report_type']] = isset($arrReport[$input['report_type']]) ?
                $arrReport[$input['report_type']] + 1 : 1;

            $topic->report = $arrReport;
            $topic->save();

            if ($alert) {
                if ($alert->user_report_count < config('angular.config.topic.limit_user_report')) {
                    $alert->user_report_count = $alert->user_report_count ? $alert->user_report_count + 1 : 1;
                } else {
                    return $alert->user_report_count;
                }
            } else {
                $input['user_report_count'] = 1;
                $alert = new Alert($input);
            }

            return $alert->save() ? $alert->user_report_count : 0;
        }

        return 0;
    }
}
