<?php

namespace App\Enums;

enum ModuleScope: string
{
    case Entidade = 'ENTIDADE';
    case Gabinete = 'GABINETE';
    case Both = 'AMBOS';
}
