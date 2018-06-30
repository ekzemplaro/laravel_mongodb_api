<?php

namespace App\Services;

use App\Events\BlockEntriesEvent;
use App\Models\User;
use App\Models\UserBlock;
use Illuminate\Support\Facades\Validator;
use App\Services\CacheService;

class UserService extends BaseService
{
    public static function validateCreate($inputs)
    {
        $roles = implode(',', array_values(config('user.roles')));
        $usernameLength = config('user.limit.username_length');
        $validationRules = [
            'password' => 'required|min:6',
            'email' => 'required|email|unique:' . User::class . ',email,,withTrashed',
            'username' => 'unique:' . User::class . ',username,,withTrashed|min:' . $usernameLength['min'] . '|max:' . $usernameLength['max'] . '|regex:/^[a-zA-Z0-9]*$/',
            'name' => 'max:20',
            'role' => "in:$roles",
        ];

        return Validator::make($inputs, $validationRules)->setAttributeNames(trans('validation.attributes.user'));
    }

    public static function valiatePassword($inputs)
    {
        $validationRules = [
            'password' => 'required|confirmed|min:6',
        ];

        return Validator::make($inputs, $validationRules)->setAttributeNames(trans('validation.attributes.user'));
    }

    public static function validateUpdate($input, $userId)
    {
        if (isset($input['email'])) {
            $rules = ['email' => 'required|email|unique:' . User::class . ',email,' . $userId];
        } elseif (isset($input['password'])) {
            $rules = ['password' => 'required|min:6|confirmed'];
        } elseif (isset($input['avatar'])) {
            $mimes = config('images.validate.user_avatar.mimes');
            $maxSize = config('images.validate.user_avatar.max_size');

            if ($input['avatar'] instanceof UploadedFile) {
                $rules['avatar'] = 'mimes:' . $mimes . '|max:' . $maxSize;
            } elseif (!empty($input['avatar'])) {
                $rules['avatar'] = 'is_base64image|base64image_mimes:' . config('images.validate.topic_image.mimes');
            }
        } else {
            $user = User::find($userId);
            $usernameLength = config('user.limit.username_length');
            $emailNotification = implode(',', array_values(config('user.email_notification')));
            $gender = implode(',', array_values(config('user.gender')));

            $rules = [
                'email_notification.*' => 'in:' . $emailNotification,
                'gender' => 'in:' . $gender,
            ];

            if (!$user->has_connected_sns) {
                $rules = [
                    'username' => 'required|min:' . $usernameLength['min'] . '|max:' . $usernameLength['max'] . '|regex:/^[a-zA-Z0-9]*$/|unique:' . User::class . ',username,' . $userId,
                ];
            }
        }

        return Validator::make($input, $rules ?? [])->setAttributeNames(trans('validation.attributes.user'));
    }

    public static function sendActivationEmail($user)
    {
        if ($user->activated) {
            return false;
        }

        $user->activation_token = [
            'token' => self::createNewToken(),
            'created_at' => time(),
        ];

        if ($user->save() && EmailService::sendMail('user.email_activation', $user->email, $user)) {
            return true;
        }

        return false;
    }

    public static function sendActivationChangedEmail($user, $email)
    {
        $user->new_email = $email;
        $user->change_email_token = [
            'token' => self::createNewToken(),
            'created_at' => time(),
        ];

        if ($user->save() && EmailService::sendMail('user.changed_email_activation', $email, $user)) {
            return true;
        }

        return false;
    }

    /*
     * |---------------------------------------------------------------------------------
     * | @send Forgot Password Email
     * |---------------------------------------------------------------------------------
     * | @modifined vungpv - datetime 2017/06/13 04pm
     * | @description :
     * | - using redis cache : set cache user_uid for token
     * | - expried 3600 senconds
     * |---------------------------------------------------------------------------------
     */
    public static function sendForgotPasswordEmail($user)
    {
        $token = self::createNewToken();
        $user->forgot_password_token = [
            'token' => $token,
            'created_at' => time(),
        ];
        CacheService::set(User::CACHE_GLOBAL_USER_FORGOT_PASSWORD_TOKEN . $user->id, $token, User::EXPRIED_USER_FORGOT_TOKEN);
        if ($user->save() && EmailService::sendMail('user.forgot_password', $user->email, $user)) {
            return true;
        }
        return false;
    }

    public static function createNewToken()
    {
        return hash_hmac('sha256', str_random(40), config('app.key'));
    }

    public static function getList($condition, $lastEvaluatedKey = null, $limit = 1)
    {
        $query = User::where('created_at', '>', 0)
            ->where('flag_index', 1);

        if (isset($condition['orderBy']) && $condition['orderBy']) {
            $query->orderBy($condition['orderBy']);
        }

        if (isset($condition['fieldFilter']) && $condition['fieldFilter'] && $condition['fieldFilterValue']) {
            if ($condition['fieldFilter'] !== 'display_name') {
                $query->where($condition['fieldFilter'], 'contains', $condition['fieldFilterValue']);
            } else {
                $query->where(function ($subQuery) use ($condition) {
                    $subQuery->orWhere('facebook_name', 'contains', $condition['fieldFilterValue'])
                        ->orWhere('twitter_screen_name', 'contains', $condition['fieldFilterValue'])
                        ->orWhere('username', 'contains', $condition['fieldFilterValue']);
                });
            }
        }

        return $query->paginate([], $limit, $lastEvaluatedKey);
    }

    public static function getById($userId)
    {
        try {
            return User::find($userId);
        } catch (\Exception $e) {
            return false;
        }
    }

    public static function blockUser($input)
    {
        foreach ($input['ids'] as $userId) {
            $user = User::find($userId);
            if ($input['action'] == config('common.block_option.block')) {
                $user->is_admin_block = time();
            } else {
                $user->is_admin_block = 0;
            }

            $user->save();

            if ($user->is_admin_block) {
                event(new BlockEntriesEvent('user', $userId));
            }
        }

        CacheService::del(User::CACHE_GLOBAL_USER_BLOCKED_KEY);

        return true;
    }

    public static function pickUpUser($input)
    {
        foreach ($input['ids'] as $userId) {
            $user = User::find($userId);
            $user->picked_up = ($input['action'] == config('common.block_option.picked_up'));
            $user->save();
        }

        CacheService::del(User::CACHE_PICKED_UP_USERS_KEY);

        return true;
    }
}
