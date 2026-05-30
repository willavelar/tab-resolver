<?php
// app/Http/Controllers/SessionController.php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class SessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Sessions/Create');
    }
}
