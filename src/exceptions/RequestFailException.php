<?php

namespace esia\exceptions;

class RequestFailException extends BaseException
{
    const CODE_TOKEN_IS_EMPTY = 500;

    protected static $codeLabels = [
        self::CODE_TOKEN_IS_EMPTY => 'Token is empty. Please set the token before',
    ];


}