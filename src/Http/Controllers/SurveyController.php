<?php

namespace Evalty\Survey\Http\Controllers;

use Illuminate\Routing\Controller;

class SurveyController extends Controller
{
    public function index()
    {
        return view('survey::index');
    }
}
