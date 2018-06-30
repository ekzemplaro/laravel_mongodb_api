<?php

namespace App\Services\Api;

use App\Models\Api\User;
use App\Models\Api\Notification;
use App\Services\CacheService;
use App\Services\UserService as GlobalUserService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;

class UserService
{
    public static function getEmailNotify()
    {
        $emailNotification = config('user.email_notification');
        $options = [];

        foreach ($emailNotification as $key => $value) {
            $options[] = [
                'value' => $value,
                'name' => trans('angular/user.profile.email_notify.' . $key),
            ];
        }
        return $options;
    }

    public static function getGenderOption()
    {
        $gender = config('user.gender');
        $options[] = [
            'value' => '',
            'name' => trans('angular/user.profile.gender.not_selected'),
        ];

        foreach ($gender as $key => $value) {
            $options[] = [
                'value' => $value,
                'name' => trans('angular/user.profile.gender.' . $key),
            ];
        }

        return $options;
    }

    public static function validateProfile($input)
    {
        $emailNotification = implode(',', array_values(config('user.email_notification')));
        $usernameLength = config('user.limit.username_length');
        $gender = implode(',', array_values(config('user.gender')));
        $input['website_url'] = $input['website_url'] ?? '';
        $input['username'] = $input['username'] ?? '';
        $usernameRule = 'required_if:has_connected_sns,false|unique:' . User::class . ',username,' . $input['id'];
        $usernameRule .= '|min:' . $usernameLength['min'] . '|max:' . $usernameLength['max'] . '|regex:/^[a-zA-Z0-9]*$/';

        $rules = [
            'username' => $usernameRule,
            'name' => 'max:20',
            'email_notification.*' => 'in:' . $emailNotification,
            'website_url' => 'url',
            'gender' => 'in:' . $gender,
            'location' => 'max:50',
            'profile' => 'max:500',
        ];

        $messages = [
            'email_notification.*.in' => trans('validation.attributes.user.email_notification'),
        ];

        return Validator::make($input, $rules, $messages)
            ->setAttributeNames(trans('validation.attributes.user'));
    }

    public static function validateAvatar($input)
    {
        $mimes = config('images.validate.user_avatar.mimes');
        $maxSize = config('images.validate.user_avatar.max_size');
        $rule = [];

        if ($input['avatar'] instanceof UploadedFile) {
            $rule = [
                'avatar' => 'required|mimes:' . $mimes . '|max:' . $maxSize,
            ];
        } elseif (isset($input['avatar']) && !empty($input['avatar'])) {
            $rule['avatar'] = 'required|is_base64image|base64image_mimes:' . config('images.validate.user_avatar.mimes');
        }

        return Validator::make($input, $rule)
            ->setAttributeNames(trans('validation.attributes.user'));
    }

    public static function updateProfile($userId, $input)
    {
        if ($user = User::find($userId)) {
            $user->app_notification = $input['app_notification'] ?? $user->app_notification;
            $user->name = $input['name'] ?? $user->name;
            $user->username = $input['username'] ?? null;
            $user->email_notification = $input['email_notification'] ?? null;
            $user->website_url = $input['website_url'] ?? null;
            $user->gender = $input['gender'] ?? '';
            $user->location = $input['location'] ?? null;
            $user->profile = $input['profile'] ?? null;
            $user->receive_notification = $input['receive_notification'] ?? false;
            return $user->save();
        }

        return false;
    }

    public static function getMasterData()
    {
        return [
            'gender' => self::getGenderOption(),
            'email_notify' => self::getEmailNotify(),
        ];
    }

    public static function getNotifications($userId)
    {
        $result = [];

        foreach (config('notification.action') as $index => $action) {
            $result[$index] = Notification::where('user_id', $userId)
                ->where('action', $action)
                ->withIndex('user_notification_index')
                ->totalCount();
        }

        return $result;
    }

    public static function updateEmail($user, $input)
    {
        $rules = [
            'email' => 'required|email|unique:' . User::class . ',email,' . $user->id . ',withTrashed',
            'password' => 'required',
        ];

        if (!Hash::check($input['password'], $user->password)) {
            return [
                'error' => true,
                'messages' => ['password' => trans('angular/user.profile.message.wrong_password')],
            ];
        }

        $validation = Validator::make($input, $rules)
            ->setAttributeNames(trans('validation.attributes.user'));

        if ($validation->fails()) {
            return [
                'error' => true,
                'messages' => $validation->errors(),
            ];
        }

        if (GlobalUserService::sendActivationChangedEmail($user, $input['email'])) {
            return [
                'error' => false,
                'messages' => ['email' => trans('angular/user.profile.message.update_success')],
            ];
        }

        return [
            'error' => true,
            'messages' => ['email' => trans('angular/user.profile.message.update_fail')],
        ];
    }

    public static function updatePassword($user, $input)
    {
        $rules = [
            'current_password' => 'required',
            'password' => 'required|confirmed|min:6',
        ];

        $validation = Validator::make($input, $rules)
            ->setAttributeNames(trans('validation.attributes.user'));

        if ($validation->fails()) {
            return [
                'error' => true,
                'messages' => $validation->errors(),
            ];
        }

        if (!Hash::check($input['current_password'], $user->password)) {
            return [
                'error' => true,
                'messages' => ['current_password' => trans('angular/user.profile.message.wrong_password')],
            ];
        }

        if ($user->update(['password' => Hash::make($input['password'])])) {
            return [
                'error' => false,
                'messages' => trans('angular/user.profile.message.update_success'),
            ];
        }

        return [
            'error' => true,
            'messages' => ['password' => trans('angular/user.profile.message.update_fail')],
        ];
    }

    public static function getPickedUpUsers()
    {
        $pickedUpUserIds = CacheService::get(User::CACHE_PICKED_UP_USERS_KEY);
        if (!$pickedUpUserIds) {
            $pickedUpUserIds = User::where('picked_up', true)->where(function ($query) {
                $query->where('is_admin_block', 0)
                    ->orWhere(function ($subQuery) {
                        $subQuery->where('is_admin_block', 'attribute_not_exists', true);
                    });
            })->get(['id'])->pluck('id');
            CacheService::set(User::CACHE_PICKED_UP_USERS_KEY, $pickedUpUserIds);
        } else {
            $pickedUpUserIds = collect($pickedUpUserIds);
        }

        if ($pickedUpUserIds->count() <= config('user.random_picked_up_users')) {
            return User::whereInTrigger('id', $pickedUpUserIds->toArray())->get();
        }

        $randomUserIds = $pickedUpUserIds->random(config('user.random_picked_up_users'))->toArray();

        return User::whereInTrigger('id', $randomUserIds)->get();
    }
}
