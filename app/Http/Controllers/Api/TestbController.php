<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\Aichi;

class TestbController extends Controller
{
	public function __invoke()
	{
	error_log ("*** TestbController index () ***");
	$cities = Aichi::orderBy('_id', 'desc')->get();
	return $cities;
	}
}
