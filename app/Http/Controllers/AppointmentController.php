<?php

namespace App\Http\Controllers;

use App\DataTables\AppointmentDataTable;
use App\DataTables\DoctorAppointmentDataTable;
use App\Events\DeleteAppointmentFromGoogleCalendar;
use App\Http\Requests\CreateAppointmentRequest;
use App\Http\Requests\CreateFrontAppointmentRequest;
use App\Http\Requests\UpdateAppointmentRequest;
use App\Models\Address;
use Illuminate\Support\Facades\Log;
use App\Models\Appointment;
use App\Models\AppointmentGoogleCalendar;
use App\Models\Doctor;
use App\Models\GoogleCalendarIntegration;
use App\Models\Notification;
use App\Models\Patient;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserGoogleAppointment;
use App\Repositories\AppointmentRepository;
use App\Repositories\GoogleCalendarRepository;
use Carbon\Carbon;
use Exception;
use Flash;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\HigherOrderBuilderProxy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HigherOrderCollectionProxy;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Yajra\DataTables\Facades\DataTables;

class AppointmentController extends AppBaseController
{
    /** @var  AppointmentRepository */
    private $appointmentRepository;

    public function __construct(AppointmentRepository $appointmentRepo)
    {
        $this->appointmentRepository = $appointmentRepo;
    }

    /**
     * @param  Request  $request
     *
     * @throws Exception
     *
     * @return Application|Factory|View
     */
    public function index(Request $request)
    {
        if ($request->ajax()) {
            return Datatables::of((new AppointmentDataTable())->get($request->only([
                'status', 'filter_date', 'payment_type',
            ])))->make(true);
        }
        $appointmentStatus = Appointment::ALL_STATUS;
        $paymentStatus = getAllPaymentStatus();
        $paymentGateway = getPaymentGateway();

        return view('appointments.index', compact('appointmentStatus', 'paymentStatus', 'paymentGateway'));
    }

    /**
     * Show the form for creating a new Appointment.
     *
     * @return Application|Factory|View
     */
    //inorder to make an appointment you need to have a token as in you have to be logged in. 
    public function create()
    {
        $data = $this->appointmentRepository->getData();
        $data['status'] = Appointment::BOOKED_STATUS_ARRAY;
        $patient = Patient::where('user_id', getLogInUserId())->first();

        return view('appointments.create', compact('data', 'patient'));
    }

    /**
     * @param  CreateAppointmentRequest  $request
     *
     * @throws ApiErrorException
     *
     * @return JsonResponse
     */
    public function store(CreateAppointmentRequest $request)
    {
// dd($request);

       
    //the app can also send data and it can be accesed through the request method. 
        $input = $request->all();
    //    dd($input);
//this here write the appointment into the database 
        $appointment = $this->appointmentRepository->store($input);

        if ($input['payment_type'] == Appointment::STRIPE) {
            $result = $this->appointmentRepository->createSession($appointment);

            return $this->sendResponse([
                'appointmentId' => $appointment->id,
                'payment_type'  => $input['payment_type'],
                $result,
            ], 'Stripe session created successfully.');
        }

        if ($input['payment_type'] == Appointment::PAYSTACK) {

            if ($request->isXmlHttpRequest()) {
                return $this->sendResponse([
                    'redirect_url'  => route('paystack.init', ['appointmentData' => $appointment]),
                    'payment_type'  => $input['payment_type'],
                    'appointmentId' => $appointment->id,
                ], 'Paystck session created successfully.');
            }

            return redirect(route('paystack.init'));
        }

        if ($input['payment_type'] == Appointment::PAYPAL) {

            if ($request->isXmlHttpRequest()) {
                return $this->sendResponse([
                    'redirect_url' => route('paypal.index', ['appointmentData' => $appointment]),
                    'payment_type' => $input['payment_type'],
                    'appointmentId' => $appointment->id,
                ], 'Paypal session created successfully.');
            }

            return redirect(route('paypal.init'));
        }

        if ($input['payment_type'] == Appointment::RAZORPAY) {
                return $this->sendResponse([
                    'payment_type' => $input['payment_type'],
                    'appointmentId' => $appointment->id,
                ], 'Razorpay session created successfully.');
        }

        if ($input['payment_type'] == Appointment::AUTHORIZE) {
            return $this->sendResponse([
                'payment_type' => $input['payment_type'],
                'appointmentId' => $appointment->id,
            ], 'Authorize session created successfully.');
        }

        if ($input['payment_type'] == Appointment::PAYTM) {
            return $this->sendResponse([
                'payment_type'  => $input['payment_type'],
                'appointmentId' => $appointment->id,
            ], 'Paytm session created successfully.');
        }

        $url = route('appointments.index');

        if (getLogInUser()->hasRole('patient')) {
            $url = route('patients.appointments.index');
        }

        $data = [
            'url'           => $url,
            'payment_type'  => $input['payment_type'],
            'appointmentId' => $appointment->id,
        ];

        // return response()->json($input , 200);
        return $this->sendResponse($data, 'Appointment created successfully.');
    }

    //TODO generate the doctor id , date of the appointment from time , to time , description , service id from service selection , 

    public function storeApi(CreateAppointmentRequest $request ){
        $input = $request->all();
        
        //this here write the appointment into the database 
                $appointment = $this->appointmentRepository->store($input);
                return response()->json("Appointment created Successfully" , 200);
    }
    /**
     * Display the specified Appointment.
     *
     * @param  Appointment  $appointment
     * @return Application|RedirectResponse|Redirector
     */
    public function show(Appointment $appointment)
    {
        if (getLogInUser()->hasRole('doctor')) {
            $doctor = Appointment::whereId($appointment->id)->whereDoctorId(getLogInUser()->doctor->id);
            if (! $doctor->exists()) {
                return redirect()->back();
            }
        } elseif (getLogInUser()->hasRole('patient')) {
            $patient = Appointment::whereId($appointment->id)->wherePatientId(getLogInUser()->patient->id);
            if (! $patient->exists()) {
                return redirect()->back();
            }
        }

        $appointment = $this->appointmentRepository->showAppointment($appointment);

        if (empty($appointment)) {
            Flash::error('Appointment not found');

            if (getLogInUser()->hasRole('patient')) {
                return redirect(route('patients.appointments.index'));
            } else {
                return redirect(route('admin.appointments.index'));
            }
        }

        if (getLogInUser()->hasRole('patient')) {
            return view('patient_appointments.show')->with('appointment', $appointment);
        } else {
            return view('appointments.show')->with('appointment', $appointment);
        }

    }

    /**
     * Remove the specified Appointment from storage.
     *
     * @param  Appointment  $appointment
     *
     * @return JsonResponse
     */
    public function destroy(Appointment $appointment)
    {
        $appointmentUniqueId = $appointment->appointment_unique_id;

        $transaction = Transaction::whereAppointmentId($appointmentUniqueId)->first();

        if ($transaction) {
            $transaction->delete();
        }

        $appointment->delete();

        return $this->sendSuccess('Appointment deleted successfully.');
    }

    /**
     * @param  Request  $request
     *
     * @throws Exception
     *
     * @return Application|Factory|View
     */
    public function doctorAppointment(Request $request)
    {
        if ($request->ajax()) {
            return DataTables::of((new DoctorAppointmentDataTable())->get($request->only([
                'status', 'filter_date', 'payment_type',
            ])))->make(true);
        }
        $appointmentStatus = Appointment::ALL_STATUS;
        $paymentStatus = getAllPaymentStatus();

        return view('doctor_appointment.index', compact('appointmentStatus', 'paymentStatus'));
    }

    /**
     * @param  Request  $request
     *
     * @return Application|Factory|View|JsonResponse
     */
    public function doctorAppointmentCalendar(Request $request)
    {
        if ($request->ajax()) {
            $input = $request->all();
            $data = $this->appointmentRepository->getAppointmentsData();

            return $this->sendResponse($data, 'Doctor Appointment calendar data retrieved successfully.');
        }

        return view('doctor_appointment.calendar');
    }

    /**
     * @param  Request  $request
     *
     *
     * @return Application|Factory|View
     */
    public function patientAppointmentCalendar(Request $request)
    {
        if ($request->ajax()) {
            $input = $request->all();
            $data = $this->appointmentRepository->getPatientAppointmentsCalendar();

            return $this->sendResponse($data, 'Patient Appointment calendar data retrieved successfully.');
        }

        return view('appointments.patient-calendar');
    }

    /**
     * @param  Request  $request
     *
     * @return Application|Factory|View|JsonResponse
     */
    public function appointmentCalendar(Request $request)
    {
        if ($request->ajax()) {
            $input = $request->all();
            $data = $this->appointmentRepository->getCalendar();

            return $this->sendResponse($data, 'Appointment calendar data retrieved successfully.');
        }

        return view('appointments.calendar');
    }

    /**
     * @param  Appointment  $appointment
     *
     * @return Application|Factory|View
     */
    public function appointmentDetail(Appointment $appointment)
    {
        $appointment = $this->appointmentRepository->showDoctorAppointment($appointment);

        return view('doctor_appointment.show', compact('appointment'));
    }

    /**
     * @param  Request  $request
     *
     * @return mixed
     */
    public function changeStatus(Request $request)
    {
        $input = $request->all();
        $appointment = Appointment::findOrFail($input['appointmentId']);
        $appointment->update([
            'status' => $input['appointmentStatus'],
        ]);
        $fullTime = $appointment->from_time.''.$appointment->from_time_type.' - '.$appointment->to_time.''.$appointment->to_time_type.' '.' '.Carbon::parse($appointment->date)->format('jS M, Y');
        $patient = Patient::whereId($appointment->patient_id)->with('user')->first();
        $doctor = Doctor::whereId($appointment->doctor_id)->with('user')->first();
        if ($input['appointmentStatus'] == Appointment::CHECK_OUT){
            Notification::create([
                'title' => Notification::APPOINTMENT_CHECKOUT_PATIENT_MSG.' '.getLogInUser()->full_name,
                'type' =>  Notification::CHECKOUT,
                'user_id' => $patient->user_id,
            ]);
            Notification::create([
                'title'   => $patient->user->full_name.'\'s appointment check out by '.getLogInUser()->full_name.' at '.$fullTime,
                'type'    => Notification::CHECKOUT,
                'user_id' => $doctor->user_id,
            ]);
        }elseif($input['appointmentStatus'] == Appointment::CANCELLED) {
            $events = UserGoogleAppointment::with(['user'])->where('appointment_id', $appointment->id)->get();

            /** @var GoogleCalendarRepository $repo */
            $repo = App::make(GoogleCalendarRepository::class);

            $repo->destroy($events);

            Notification::create([
                'title'   => Notification::APPOINTMENT_CANCEL_PATIENT_MSG.' '.getLogInUser()->full_name,
                'type'    => Notification::CANCELED,
                'user_id' => $patient->user_id,
            ]);
            Notification::create([
                'title'   => $patient->user->full_name.'\'s appointment cancelled by'.getLogInUser()->full_name.' at '.$fullTime,
                'type'    => Notification::CANCELED,
                'user_id' => $doctor->user_id,
            ]);
        }

        return $this->sendSuccess('Status updated successfully.');
    }

    /**
     * @param  Request  $request
     *
     * @return mixed
     */
    public function cancelStatus(Request $request)
    {
        $appointment = Appointment::findOrFail($request['appointmentId']);
        $appointment->update([
            'status' => Appointment::CANCELLED,
        ]);

        $events = UserGoogleAppointment::with('user')
            ->where('appointment_id', $appointment->id)
            ->get()
            ->groupBy('user_id');

        foreach ($events as $userID => $event) {
            $user = $event[0]->user;
            DeleteAppointmentFromGoogleCalendar::dispatch($event, $user);
        }

        $fullTime = $appointment->from_time.''.$appointment->from_time_type.' - '.$appointment->to_time.''.$appointment->to_time_type.' '.' '.Carbon::parse($appointment->date)->format('jS M, Y');
        $patient = Patient::whereId($appointment->patient_id)->with('user')->first();

        $doctor = Doctor::whereId($appointment->doctor_id)->with('user')->first();
        Notification::create([
            'title'   => $patient->user->full_name.' '.Notification::APPOINTMENT_CANCEL_DOCTOR_MSG.' '.$fullTime,
            'type'    => Notification::CANCELED,
            'user_id' => $doctor->user_id,
        ]);


        return $this->sendSuccess('Appointment Cancelled.');
    }


    /**
     * @param  CreateFrontAppointmentRequest  $request
     *
     * @throws ApiErrorException
     *
     * @return JsonResponse
     */
    public function frontAppointmentBook(CreateFrontAppointmentRequest $request)
    {
        $input = $request->all();
        $appointment = $this->appointmentRepository->frontSideStore($input);

        if ($input['payment_type'] == Appointment::STRIPE) {
            $result = $this->appointmentRepository->createSession($appointment);

            return $this->sendResponse([
                'payment_type' => $input['payment_type'],
                $result,
            ], 'Stripe session created successfully.');
        }

        if ($input['payment_type'] == Appointment::PAYPAL) {

            if ($request->isXmlHttpRequest()) {
                return $this->sendResponse([
                    'redirect_url' => route('paypal.index', ['appointmentData' => $appointment]),
                    'payment_type' => $input['payment_type'],
                    'appointmentId' => $appointment->id,
                ], 'Paypal session created successfully.');
            }
        }

        if ($input['payment_type'] == Appointment::PAYSTACK) {

            if ($request->isXmlHttpRequest()) {
                return $this->sendResponse([
                    'redirect_url' => route('paystack.init', ['appointmentData' => $appointment]),
                    'payment_type' => $input['payment_type'],
                ], 'Paystck session created successfully.');
            }

            return redirect(route('paystack.init'));
        }

        if ($input['payment_type'] == Appointment::RAZORPAY) {
            return $this->sendResponse([
                'payment_type' => $input['payment_type'],
                'appointmentId' => $appointment->id,
            ], 'Razorpay session created successfully.');
        }

        if ($input['payment_type'] == Appointment::PAYTM) {
            return $this->sendResponse([
                'payment_type'  => $input['payment_type'],
                'appointmentId' => $appointment->id,
            ], 'Paytm session created successfully.');
        }

        if ($input['payment_type'] == Appointment::AUTHORIZE) {
            return $this->sendResponse([
                'payment_type' => $input['payment_type'],
                'appointmentId' => $appointment->id,
            ], 'Authorize session created successfully.');
        }

        $data = $input['payment_type'];

        return $this->sendResponse($data, 'Appointment Booked successfully');
    }


    /**
     * @param  Request  $request
     *
     * @return RedirectResponse
     */
    public function frontHomeAppointmentBook(Request $request)
    {
        $data = $request->all();
        
        return redirect()->route('medicalAppointment')->with(['data'=>$data]);
    }

    /**
     * @param  Request  $request
     *
     * @throws Exception
     *
     * @return HigherOrderBuilderProxy|mixed|string
     */
    public function getPatientName(Request $request)
    {
        $checkRecord = User::whereEmail($request->email)->whereType(User::PATIENT)->first();

        if ($checkRecord != '') {
            return $this->sendResponse($checkRecord->full_name, 'Patient name retrieved successfully.');
        }

        return false;
    }

    /**
     * @param  Request  $request
     *
     * @throws ApiErrorException
     *
     * @return Application|RedirectResponse|Redirector
     */
    public function paymentSuccess(Request $request)
    {
        $sessionId = $request->get('session_id');
        if (empty($sessionId)) {
            throw new UnprocessableEntityHttpException('session id is required');
        }
        setStripeApiKey();

        $sessionData = \Stripe\Checkout\Session::retrieve($sessionId);
        $appointment = Appointment::whereAppointmentUniqueId($sessionData->client_reference_id)->first();
        $patientId = User::whereEmail($sessionData->customer_details->email)->pluck('id')->first();

        $transaction = [
            'user_id'        => $patientId,
            'transaction_id' => $sessionData->id,
            'appointment_id' => $sessionData->client_reference_id,
            'amount'         => intval($sessionData->amount_total / 100),
            'type'           => Appointment::STRIPE,
            'meta'           => $sessionData,
        ];

        Transaction::create($transaction);

        $appointment->update([
            'payment_method' => Appointment::STRIPE,
            'payment_type'   => Appointment::PAID,
        ]);

        Flash::success('Appointment created successfully and Payment is completed.');

        $patient = Patient::whereUserId($patientId)->with('user')->first();
        Notification::create([
            'title'   => Notification::APPOINTMENT_PAYMENT_DONE_PATIENT_MSG,
            'type'    => Notification::PAYMENT_DONE,
            'user_id' => $patient->user_id,
        ]);

        if (parse_url(url()->previous(), PHP_URL_PATH) == '/medical-appointment') {
            return redirect(route('medicalAppointment'));
        }

        if (! getLogInUser()) {
            return redirect(route('medical'));
        }

        if (getLogInUser()->hasRole('patient')) {
            return redirect(route('patients.appointments.index'));
        }

        return redirect(route('appointments.index'));
    }

    /**
     * @return Application|RedirectResponse|Redirector
     */
    public function handleFailedPayment()
    {
        setStripeApiKey();
        
        Flash::error('Appointment created successfully and Payment is not completed.');
        
        if (! getLogInUser()) {
            return redirect(route('medicalAppointment'));
        }

        if (getLogInUser()->hasRole('patient')) {
            return redirect(route('patients.appointments.index'));
        }

        return redirect(route('appointments.index'));
    }

    /**
     * @param  Request  $request
     *
     * @throws ApiErrorException
     *
     * @return mixed
     */
    public function appointmentPayment(Request $request)
    {
        $appointmentId = $request['appointmentId'];
        $appointment = Appointment::whereId($appointmentId)->first();

        $result = $this->appointmentRepository->createSession($appointment);

        return $this->sendResponse($result, 'Session created successfully.');
    }

    /**
     * @param  Request  $request
     *
     * @return mixed
     */
    public function changePaymentStatus(Request $request)
    {
        $input = $request->all();
        $appointment = Appointment::findOrFail($input['appointmentId']);
        $appointment->update([
            'payment_type'   => $input['paymentStatus'],
            'payment_method' => $input['paymentMethod'],
        ]);
        $fullTime = $appointment->from_time.''.$appointment->from_time_type.' - '.$appointment->to_time.''.$appointment->to_time_type.' '.' '.Carbon::parse($appointment->date)->format('jS M, Y'); 
        $patient = Patient::whereId($appointment->patient_id)->with('user')->first();
        $doctor = Patient::whereId($appointment->doctor_id)->with('user')->first();
        Notification::create([
            'title' => getLogInUser()->full_name.' changed the payment status '.Appointment::PAYMENT_TYPE[Appointment::PENDING].' to '.Appointment::PAYMENT_TYPE[$appointment->payment_type].' for appointment '.$fullTime,
            'type' =>  Notification::PAYMENT_DONE,
            'user_id' => $patient->user_id,
        ]);

        return $this->sendSuccess('Payment status updated successfully.');
    }

    /**
     * @param  $patient_id
     * @param  $appointment_unique_id
     *
     * @return  RedirectResponse
     */
    public function cancelAppointment($patient_id, $appointment_unique_id)
    {
        $uniqueId  = Crypt::decryptString($appointment_unique_id);
        $appointment = Appointment::whereAppointmentUniqueId($uniqueId)->wherePatientId($patient_id)->first();
        
        $appointment->update(['status'=>Appointment::CANCELLED]);
        
        return redirect(route('medical'));
    }

    /**
     * @param Doctor $doctor
     *
     *
     * @return RedirectResponse
     */
    public function doctorBookAppointment(Doctor $doctor)
    {
        $data = [];
        $data['doctor_id'] = $doctor['id'];
        
        return redirect()->route('medicalAppointment')->with(['data'=>$data]);
    }

    /**
     * @param  Service  $service
     *
     * @return RedirectResponse
     */
    public function serviceBookAppointment(Service $service)
    {
        $data = [];
        $data['service'] = Service::whereStatus(Service::ACTIVE)->get()->pluck('name', 'id');
        $data['service_id'] = $service['id'];

        return redirect()->route('medicalAppointment')->with(['data' => $data]);
    }

    /**
     * @param  Request  $request
     *
     * @return bool|JsonResponse
     */
    public function createGoogleEventForDoctor(Request $request)
    {
        if ($request->isXmlHttpRequest()) {
            return $this->sendSuccess('Operation performed successfully');
        }

        return true;
    }


    /**
     * @param  Request  $request
     *
     * @return bool|JsonResponse
     */
    public function createGoogleEventForPatient(Request $request)
    {
        if ($request->isXmlHttpRequest()) {
            return $this->sendSuccess('Operation performed successfully');
        }

        return true;
    }
}
