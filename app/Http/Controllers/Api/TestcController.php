<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\City;

class TestcController extends Controller
{
	public function __invoke ()
	{
	error_log ("*** TestcController index () ***");
	$cities = City::orderBy('_id', 'desc')->get();
	return $cities;
	}
}
