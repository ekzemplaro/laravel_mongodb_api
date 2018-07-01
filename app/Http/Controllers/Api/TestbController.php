<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\City;

class TestbController extends Controller
{
	public function __invoke()
	{
	error_log ("*** TestbController index () ***");
	$cities = City::orderBy('_id', 'desc')->get();
	return $cities;
	}
}
