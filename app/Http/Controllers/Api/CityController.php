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
	$cities = City::orderBy('_id', 'desc')->get();
	return $cities;
	}

	public function all()
        {
        $cities = City::all();
        return $cities;
        }

        public function find($id)
        {
        $cities = City::find($id);
        return $cities;
        }

        public function where($name)
        {
        $cities = City::where('name','=',$name)->get();
        return $cities;
        }

        public function between($min,$max)
        {
        $cities = City::whereBetween('population',[(int)$min,(int)$max])->get();
        return $cities;
        }
}
