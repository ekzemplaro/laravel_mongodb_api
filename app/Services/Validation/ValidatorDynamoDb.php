<?php

namespace App\Services\Validation;

use Illuminate\Validation\Validator as IlluminateValidator;
use Symfony\Component\Translation\TranslatorInterface;
use Aws\DynamoDb\Exception\DynamoDbException;
use App\Console\Commands\DynamoDB\DBClient;

class ValidatorDynamoDb extends IlluminateValidator
{
    protected $customMessages = [];

    public function __construct(
        TranslatorInterface $translator,
        array $data,
        array $rules,
        array $messages = [],
        array $customAttributes = []
    ) {
        parent::__construct($translator, $data, $rules, $messages, $customAttributes);
        $this->setCustomStuff();
    }

    protected function setCustomStuff()
    {
        $this->setCustomMessages($this->customMessages);
    }

    protected function validateUnique($attribute, $value, $parameters)
    {
        $this->requireParameterCount(1, $parameters, 'unique');
        $model = $parameters[0];

        $column = isset($parameters[1]) && $parameters[1] !== 'NULL'
            ? $parameters[1] : $this->guessColumnForQuery($attribute);

        $ignoreId = isset($parameters[2]) && $parameters[2] !== ''
            ? $parameters[2] : '0';

        $query = (new $model)::where($column, $value);
        if (isset($parameters[3]) && $parameters[3] == 'withTrashed') {
            $query = $query->withTrashed();
        }

        $query = $query->first();
        if ($query) {
            return $query->id == $ignoreId;
        }
        return true;
    }
}
