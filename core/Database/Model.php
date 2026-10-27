<?php

declare(strict_types=1);

namespace Wuyoo\Database;

abstract class Model extends \Illuminate\Database\Eloquent\Model
{
    public static function resolveConnection($connection = null)
    {
        DB::manager();
        return parent::resolveConnection($connection);
    }
}
