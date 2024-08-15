<?php

namespace DTApi\Http\Controllers;

use DTApi\Models\Job;
use DTApi\Models\Distance;
use Illuminate\Http\Request;
use DTApi\Repository\BookingRepository;

/**
 * Class BookingController
 * Handles the booking-related operations.
 */
class BookingController extends Controller
{
    /**
     * @var BookingRepository
     */
    protected $bookingRepository;

    /**
     * BookingController constructor.
     * 
     * @param BookingRepository $bookingRepository
     */
    public function __construct(BookingRepository $bookingRepository)
    {
        $this->bookingRepository = $bookingRepository;
    }

    /**
     * Display a list of jobs based on the user's role or ID.
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $userId = $request->get('user_id');
        $userType = $request->__authenticatedUser->user_type;

        if ($userId) {
            $response = $this->bookingRepository->getUsersJobs($userId);
        } elseif (in_array($userType, [env('ADMIN_ROLE_ID'), env('SUPERADMIN_ROLE_ID')])) {
            $response = $this->bookingRepository->getAll($request);
        }

        return response()->json($response);
    }

    /**
     * Show the details of a specific job.
     * 
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $job = $this->bookingRepository->with('translatorJobRel.user')->find($id);
        return response()->json($job);
    }

    /**
     * Store a new job in the database.
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $data = $request->all();
        $response = $this->bookingRepository->store($request->__authenticatedUser, $data);

        return response()->json($response);
    }

    /**
     * Update an existing job.
     * 
     * @param int $id
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function update($id, Request $request)
    {
        $data = $request->except(['_token', 'submit']);
        $user = $request->__authenticatedUser;
        $response = $this->bookingRepository->updateJob($id, $data, $user);

        return response()->json($response);
    }

    /**
     * Send an immediate job email.
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function immediateJobEmail(Request $request)
    {
        $data = $request->all();
        $response = $this->bookingRepository->storeJobEmail($data);

        return response()->json($response);
    }

    /**
     * Get the job history for a specific user.
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse|null
     */
    public function getHistory(Request $request)
    {
        $userId = $request->get('user_id');

        if ($userId) {
            $response = $this->bookingRepository->getUsersJobsHistory($userId, $request);
            return response()->json($response);
        }

        return null;
    }

    /**
     * Accept a job.
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function acceptJob(Request $request)
    {
        $data = $request->all();
        $user = $request->__authenticatedUser;
        $response = $this->bookingRepository->acceptJob($data, $user);

        return response()->json($response);
    }

    /**
     * Accept a job by its ID.
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function acceptJobWithId(Request $request)
    {
        $jobId = $request->get('job_id');
        $user = $request->__authenticatedUser;
        $response = $this->bookingRepository->acceptJobWithId($jobId, $user);

        return response()->json($response);
    }

    /**
     * Cancel a job.
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function cancelJob(Request $request)
    {
        $data = $request->all();
        $user = $request->__authenticatedUser;
        $response = $this->bookingRepository->cancelJobAjax($data, $user);

        return response()->json($response);
    }

    /**
     * End a job.
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function endJob(Request $request)
    {
        $data = $request->all();
        $response = $this->bookingRepository->endJob($data);

        return response()->json($response);
    }

    /**
     * Mark a job as 'customer not called'.
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function customerNotCall(Request $request)
    {
        $data = $request->all();
        $response = $this->bookingRepository->customerNotCall($data);

        return response()->json($response);
    }

    /**
     * Get potential jobs for a user.
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPotentialJobs(Request $request)
    {
        $user = $request->__authenticatedUser;
        $response = $this->bookingRepository->getPotentialJobs($user);

        return response()->json($response);
    }

    /**
     * Feed the distance data for a job.
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse|string
     */
    public function distanceFeed(Request $request)
    {
        $data = $request->all();
        $jobId = $data['jobid'] ?? null;

        // Update Distance table
        if (!empty($data['distance']) || !empty($data['time'])) {
            Distance::where('job_id', $jobId)->update([
                'distance' => $data['distance'] ?? '',
                'time' => $data['time'] ?? '',
            ]);
        }

        // Update Job table
        if (!empty($data['admincomment']) || !empty($data['session_time']) || !empty($data['flagged']) || !empty($data['manually_handled']) || !empty($data['by_admin'])) {
            $adminComment = $data['admincomment'] ?? '';
            $sessionTime = $data['session_time'] ?? '';
            $flagged = $data['flagged'] == 'true' ? 'yes' : 'no';
            $manuallyHandled = $data['manually_handled'] == 'true' ? 'yes' : 'no';
            $byAdmin = $data['by_admin'] == 'true' ? 'yes' : 'no';

            if ($flagged === 'yes' && empty($adminComment)) {
                return "Please, add comment";
            }

            Job::where('id', $jobId)->update([
                'admin_comments' => $adminComment,
                'flagged' => $flagged,
                'session_time' => $sessionTime,
                'manually_handled' => $manuallyHandled,
                'by_admin' => $byAdmin,
            ]);
        }

        return response()->json(['message' => 'Record updated!']);
    }

    /**
     * Reopen a job.
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function reopen(Request $request)
    {
        $data = $request->all();
        $response = $this->bookingRepository->reopen($data);

        return response()->json($response);
    }

    /**
     * Resend notifications to translators.
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function resendNotifications(Request $request)
    {
        $data = $request->all();
        $job = $this->bookingRepository->find($data['jobid']);
        $jobData = $this->bookingRepository->jobToData($job);

        $this->bookingRepository->sendNotificationTranslator($job, $jobData, '*');

        return response()->json(['success' => 'Push sent']);
    }

    /**
     * Resend SMS notifications to translators.
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function resendSMSNotifications(Request $request)
    {
        $data = $request->all();
        $job = $this->bookingRepository->find($data['jobid']);
        $jobData = $this->bookingRepository->jobToData($job);

        try {
            $this->bookingRepository->sendSMSNotificationToTranslator($job);
            return response()->json(['success' => 'SMS sent']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()]);
        }
    }
}
