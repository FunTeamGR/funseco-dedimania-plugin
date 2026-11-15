<?php
namespace Funseco\Dedimania\Commands;

/** /dedisectms: the same table with the time of each sector instead of the running time. */
class DediSecTimesCommand extends DediCpTimesCommand
{
    protected string $name = 'dedisectms';
    protected string $description = "Displays all Dedimania records' sector times";
    protected string $headerKey = 'sectms_header';
    protected bool $sectors = true;
}
