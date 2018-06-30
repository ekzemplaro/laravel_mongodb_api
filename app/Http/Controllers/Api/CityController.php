<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

use App\Models\City;

class CityController extends Controller
{
    public function index ()
	{
	error_log ("*** CityController index () ***");
	$cities = City::all();
//	return ['a','b','c'];
	return $cities;
	}
}
