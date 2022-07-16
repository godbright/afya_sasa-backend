<?php

namespace App\Http\Controllers;

use App\DataTable\UserDataTable;
use App\Http\Requests\CreateQualificationRequest;
use App\Http\Requests\CreateUserRequest;
use App\Http\Requests\UpdateChangePasswordRequest;
use App\Http\Requests\UpdateUserProfileRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorSession;
use App\Models\Specialization;
use App\Models\User;
use App\Models\Visit;
use App\Repositories\UserRepository;
use Carbon\Carbon;
use DB;
use Exception;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laracasts\Flash\Flash;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Yajra\DataTables\DataTables;

class UserControllerApi extends AppBaseController
{

    /**
     * @var UserRepository
     */
    public $userRepo;

    /**
     * UserControllerApi constructor.
     *
     * @param  UserRepository  $userRepository
     */
    public function __construct(UserRepository $userRepository)
    {
        $this->userRepo = $userRepository;
    }

    /**
     * Display a listing of the resource.
     *
     * @param  Request  $request
     * @return Application|Factory|View
     * @throws Exception
     *
     */
    public function index()
    {
        $doctors= Doctor::with(['user'])->get();
        // $doctors = $doctors->user;
        return response()->json($doctors,200);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Application|Factory|View
     */
    public function create()
    {
        $specializations = Specialization::pluck('name', 'id')->toArray();
        $country = $this->userRepo->getCountries();
        $bloodGroup = Doctor::BLOOD_GROUP_ARRAY;

        return view('doctors.create', compact('specializations', 'country', 'bloodGroup'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  CreateUserRequest  $request
     *
     * @return Application|RedirectResponse|Redirector
     */
    public function store(CreateUserRequest $request)
    {
        $input = $request->all();
        $this->userRepo->store($input);

        Flash::success('Doctor created successfully.');

        return redirect(route('doctors.index'));
    }

    /**
     * @param  Doctor  $doctor
     *
     * @throws Exception
     *
     * @return Application|Factory|View|RedirectResponse
     */
    public function show(Doctor $doctor)
    {
      //TODO Get the logged in user and retrieve the doctors that the patient has appointments with.  

        // if (getLogInUser()->hasRole('patient')) {
        //     $doctorAppointment = Appointment::whereDoctorId($doctor)->wherePatientId(getLogInUser()->patient->id);
        //     if (! $doctorAppointment->exists()) {
        //         return redirect()->back();
        //     }
        // }

//With the  doctor ID we can get all the related information of that particular doctor      
       $todayDate = Carbon::now()->format('Y-m-d');
      
       $doctor['data'] = Doctor::with([
           'user.address', 
           'specializations', 
           'appointments.patient.user',
       ])->whereId($doctor)->first();
     
       $doctor['doctorSession'] = DoctorSession::whereDoctorId($doctor)->get();
       $doctor['appointments'] = DataTables::of((new UserDataTable())->getAppointment($doctor))->make(true);
       $doctor['appointmentStatus'] = Appointment::ALL_STATUS;
       $doctor['totalAppointmentCount'] = Appointment::whereDoctorId($doctor)->count();
       $doctor['todayAppointmentCount'] = Appointment::whereDoctorId($doctor)->where('date', '=',
           $todayDate)->count();
       $doctor['upcomingAppointmentCount'] = Appointment::whereDoctorId($doctor)->where('date', '>',
           $todayDate)->count();
        return response()->json($doctor, 200);
    }

    //A doctor their specialization and user information 
    public function getDocSpecialization($id){ 

        $user= Doctor::where('user_id',$id)->first();
     
        return response()->json($user, 200);
    }



}