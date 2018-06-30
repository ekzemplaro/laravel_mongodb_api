<?php

namespace App\Services\Validation;

use Illuminate\Support\ServiceProvider;

class ValidationDynamoDbServiceProvider extends ServiceProvider
{
    public function register()
    {
    }

    public function boot()
    {
        $this->app->validator->resolver(function ($translator, $data, $rules, $messages = [], $customAttributes = []) {
            return new ValidatorDynamoDb($translator, $data, $rules, $messages, $customAttributes);
        });
    }
}
