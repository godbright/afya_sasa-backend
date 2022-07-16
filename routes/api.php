<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\RegisteredUserControllerApi;
use App\Http\Controllers\SpecializationControllerApi;
use App\Http\Controllers\AppointmentControllerApi;
use App\Http\Controllers\AuthenticatedSessionControllerApi;
use App\Http\Controllers\UserControllerApi;


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

// Route::get('/word', function(){return "i am alive";});

Route::post('/register', [RegisteredUserControllerApi::class, 'store'])
->middleware('guest')
->name('register');
             

Route::post('/login', [AuthenticatedSessionControllerApi::class, 'store'])
                ->middleware('guest');


// Route::get('doctors/{doctor}', [UserControllerApi::class, 'show']);


//TODO GET SPECILIZATIONS WITH PARTICULAR DOCS 
Route::get('specs',[SpecializationControllerApi::class,'index']);

//Get all doctors  information 
Route::get('doctors',[UserControllerApi::class,'index']);

//Get doctors 
Route::get('doctors/{id}',[UserControllerApi::class,'getDocSpecialization']);

//store the user_id in the shared preferences and then use it to call on doc-> user information 
//Get specializations and the doctors within the specialization 
Route::get('specs/{id}',[SpecializationControllerApi::class,'getSpecialization']);



Route::post('appointment',[AppointmentControllerApi::class,'storeApi']);


//TODO api to retrieve  a list of user appointments.  

Route::get('appointmentlist/{id}',[AppointmentControllerApi::class,'patientAppointmentCalendar']);