<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\City;

class TestaController extends Controller
{
	public function __invoke ()
	{
	error_log ("*** TestaController index () ***");
	$cities = City::orderBy('_id', 'desc')->get();
	return $cities;
	}
}
