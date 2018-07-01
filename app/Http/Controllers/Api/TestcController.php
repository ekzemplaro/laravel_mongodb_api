<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\Chiba;

class TestcController extends Controller
{
	public function __invoke ()
	{
	error_log ("*** TestcController index () ***");
	$cities = Chiba::orderBy('_id', 'desc')->get();
	return $cities;
	}
}
