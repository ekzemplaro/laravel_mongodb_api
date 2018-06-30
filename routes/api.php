<?php

use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});

Route::group(['middleware' => ['api']], function(){
  Route::resource('city', 'Api\CityController');
});

  Route::get('all', 'Api\CityController@all');
  Route::get('find/{id}', 'Api\CityController@find');
  Route::get('where/{name}', 'Api\CityController@where');
  Route::get('between/{min}/{max}', 'Api\CityController@between');
