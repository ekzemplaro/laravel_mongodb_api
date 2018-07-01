<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\Tochigi;

class TestaController extends Controller
{
	public function __invoke ()
	{
	error_log ("*** TestaController index () ***");
	$cities = Tochigi::orderBy('_id', 'desc')->get();
	return $cities;
	}
}
