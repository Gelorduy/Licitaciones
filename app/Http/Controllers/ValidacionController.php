<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class ValidacionController extends Controller
{
    public function index()
    {
        return Inertia::render('Validacion/Index');
    }
}
