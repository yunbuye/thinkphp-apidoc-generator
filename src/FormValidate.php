<?php


namespace Yunbuye\ThinkApiDoc;

use think\Exception;
use think\exception\HttpException;
use think\Validate;

class FormValidate extends Validate
{
    /**
     * FormValidate constructor.
     * @param array $rules
     * @param array $message
     * @param array $field
     * @param bool $auto_validate 自动验证，及创建成功后马上验证
     */

    public function __construct(array $rules = [], array $message = [], array $field = [], $auto_validate = true)
    {
        parent::__construct($rules, $message, $field);

        if ($auto_validate) {
            $this->validateResolved();
        }
    }

    public function getRules()
    {
        return $this->rule;
    }

    public function getMessages()
    {
        return $this->message;
    }

    /**
     * Validate the class instance.
     *
     * @return void
     */
    public function validateResolved()
    {
        $param = request()->param();
        $result = $this->check($param);
        if ($result !== true) {
            $this->failedValidation();
        }
    }

    public function failedValidation()
    {
        $statusCode = 422;
        $err = (array)$this->getError();
        $message = implode(";\n", $err);
        throw new HttpException($statusCode, $message);
    }

}