<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class HomeController extends Controller
{
    /**
     * Display the Bconnect home page.
     */
    public function index(): View
    {
        return view('home');
    }
}



