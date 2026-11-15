<?php
namespace Funseco\Dedimania\Commands;



class DediNewCommand extends RankingCommand
{
    protected string $name = 'dedinew';
    protected string $description = 'Shows newly driven Dedimania records';
    protected string $mode = \Funseco\Dedimania\Application\RankingLine::ONLY_NEW;
}
