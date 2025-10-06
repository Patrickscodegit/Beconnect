<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class Schedules extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';
    
    protected static string $view = 'filament.pages.schedules';
    
    protected static ?string $navigationLabel = 'Schedules';
    
    protected static ?int $navigationSort = 2;
    
    protected static ?string $title = 'Shipping Schedules';
}