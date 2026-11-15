<?php
namespace Funseco\Dedimania\Commands;



class DediLiveCommand extends RankingCommand
{
    protected string $name = 'dedilive';
    protected string $description = 'Shows Dedimania records of online players';
    protected string $mode = \Funseco\Dedimania\Application\RankingLine::DURING;
}
