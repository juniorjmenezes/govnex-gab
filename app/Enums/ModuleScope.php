<?php

namespace App\Enums;

enum ModuleScope: string
{
    case Entity = 'ENTIDADE';
    case Office = 'GABINETE';
    case Both = 'AMBOS';
}
