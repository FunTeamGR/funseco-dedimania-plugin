<?php
namespace Funseco\Dedimania\Infrastructure;

use Funseco\Core\SharedKernel\CurrentDedimaniaRecords;
use Funseco\Core\SharedKernel\Values\RecordCollection;

/** The current map's Dedimania records for other plugins (scoreboard lists and windows). */
class CurrentDedimaniaRecordList implements CurrentDedimaniaRecords
{
    public function records(): RecordCollection
    {
        return new RecordCollection(DedimaniaRecords::all()->values()->all());
    }
}
