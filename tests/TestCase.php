<?php

namespace Funseco\Dedimania\Tests;

use Funseco\Core\Testing\TestCase as CoreTestCase;
use Funseco\Records\RecordsServiceProvider;
use Funseco\Checkpoints\CheckpointsServiceProvider;
use Funseco\Dedimania\DedimaniaServiceProvider;

abstract class TestCase extends CoreTestCase
{
    /** Dependencies first, then this plugin (isolated runs load only these). */
    protected function plugins(): array
    {
        return [
            RecordsServiceProvider::class,
            CheckpointsServiceProvider::class,
            DedimaniaServiceProvider::class,
        ];
    }
}
