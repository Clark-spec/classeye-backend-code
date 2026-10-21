<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;

abstract class Controller
{
    // Laravel 11's slim base controller does not include these traits by
    // default. Adding them here lets every controller use $this->authorize().
    use AuthorizesRequests, ValidatesRequests;
}
