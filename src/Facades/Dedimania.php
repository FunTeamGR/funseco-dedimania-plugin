<?php

namespace Funseco\Dedimania\Facades;

use Illuminate\Support\Facades\Facade;
use Funseco\Dedimania\DedimaniaService;

class Dedimania extends Facade
{
    protected static function getFacadeAccessor()
    {
        return DedimaniaService::class;
    }
}
