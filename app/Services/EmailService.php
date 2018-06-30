<?php

namespace App\Services;

use Mail;
use Illuminate\Support\Facades\View;

class EmailService extends BaseService
{
    /**
     * @param        $layout
     * @param        $emailTo
     * @param array  $data
     * @param null   $fromUser
     * @param string $format
     *
     * @return bool
     */
    public static function sendMail($layout, $emailTo, $data = [], $fromUser = null, $format = 'text/html')
    {
        $lang = config('app.locale');
        $layoutPath = 'emails.' . $lang . '.' . $layout;
        if (View::exists($layoutPath)) {
            $view = View::make($layoutPath)->with('data', $data)->renderSections();
            $subject = trim($view['subject']);
            $body = $view['body'];

            Mail::queue([], [], function ($message) use ($emailTo, $subject, $body, $fromUser, $format) {
                $message->to($emailTo);
                $message->subject($subject);
                $message->setBody($body, $format);
                if ($fromUser) {
                    $message->from($fromUser->email, $fromUser->name);
                } else {
                    $message->from(config('mail.from.address'), config('mail.from.name'));
                }
            });

            return true;
        }

        return false;
    }
}
