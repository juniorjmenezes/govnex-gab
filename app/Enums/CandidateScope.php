<?php

namespace App\Enums;

enum CandidateScope: string
{
    case National = 'nacional';
    case State = 'estadual';
    case Municipal = 'municipal';
}
