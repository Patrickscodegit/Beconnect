<?php

namespace App\Enums;

enum CostSide: string
{
    case POL = 'POL';
    case POD = 'POD';
    case SEA = 'SEA';
    case ADMIN = 'ADMIN';
    case INLAND = 'INLAND';
    case AIR = 'AIR';
}

