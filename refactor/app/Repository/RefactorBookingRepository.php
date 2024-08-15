<?php

namespace DTApi\Repository;

use DTApi\Events\SessionEnded;
use DTApi\Helpers\SendSMSHelper;
use Event;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use Illuminate\Http\Request;
use DTApi\Models\Translator;
use DTApi\Mailers\AppMailer;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCreated;
use DTApi\Events\JobWasCanceled;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository
{

    protected $model;
    protected $mailer;
    protected $logger;
    protected $immediateTime = 5;

    /**
     * JobRepository constructor.
     *
     * @param Job $model
     * @param MailerInterface $mailer
     */
    public function __construct(Job $model, MailerInterface $mailer)
    {
        parent::__construct($model);
        $this->mailer = $mailer;

        // Initialize logger for admin actions with a custom file handler
        $this->logger = new Logger('admin_logger');
        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    /**
     * Get user-specific jobs based on user type (customer/translator).
     *
     * @param int $userId
     * @return array
     */
    public function getUsersJobs(int $userId): array
    {
        $user = User::find($userId);
        $userType = '';
        $emergencyJobs = [];
        $normalJobs = [];

        if ($user && $user->is('customer')) {
            // Fetch customer jobs with specific relationships and conditions
            $jobs = $user->jobs()
                         ->with(['user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback'])
                         ->whereIn('status', ['pending', 'assigned', 'started'])
                         ->orderBy('due', 'asc')
                         ->get();
            $userType = 'customer';
        } elseif ($user && $user->is('translator')) {
            // Fetch translator jobs
            $jobs = Job::getTranslatorJobs($user->id, 'new')->pluck('jobs')->all();
            $userType = 'translator';
        }

        if ($jobs) {
            foreach ($jobs as $job) {
                // Separate emergency and normal jobs
                if ($job->immediate === 'yes') {
                    $emergencyJobs[] = $job;
                } else {
                    $normalJobs[] = $job;
                }
            }

            // Sort normal jobs by due date and check if the user can access them
            $normalJobs = collect($normalJobs)
                            ->each(fn($item) => $item['usercheck'] = Job::checkParticularJob($userId, $item))
                            ->sortBy('due')
                            ->all();
        }

        return [
            'emergencyJobs' => $emergencyJobs,
            'normalJobs' => $normalJobs,
            'user' => $user,
            'userType' => $userType,
        ];
    }

    /**
     * Get user's job history based on user type (customer/translator).
     *
     * @param int $userId
     * @param Request $request
     * @return array
     */
    public function getUsersJobsHistory(int $userId, Request $request): array
    {
        $currentPage = $request->get('page', 1);
        $user = User::find($userId);
        $userType = '';
        $emergencyJobs = [];
        $normalJobs = [];
        $numPages = 0;

        if ($user && $user->is('customer')) {
            // Fetch completed jobs for customers with specific relationships
            $jobs = $user->jobs()
                         ->with(['user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance'])
                         ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
                         ->orderBy('due', 'desc')
                         ->paginate(15);
            $userType = 'customer';
        } elseif ($user && $user->is('translator')) {
            // Fetch historic jobs for translators
            $jobs = Job::getTranslatorJobsHistoric($user->id, 'historic', $currentPage);
            $totalJobs = $jobs->total();
            $numPages = ceil($totalJobs / 15);
            $userType = 'translator';
            $normalJobs = $jobs;
        }

        return [
            'emergencyJobs' => $emergencyJobs,
            'normalJobs' => $normalJobs,
            'jobs' => $jobs,
            'user' => $user,
            'userType' => $userType,
            'numPages' => $numPages,
            'currentPage' => $currentPage,
        ];
    }

      /**
     * Store a new job based on user input data.
     *
     * @param User $user
     * @param array $data
     * @return array
     */
    public function store(User $user, array $data): array
    {
        // Check if the user is a customer
        if ($user->user_type !== env('CUSTOMER_ROLE_ID')) {
            return $this->createErrorResponse("Translator cannot create a booking");
        }

        // Validate the necessary fields
        $validationResponse = $this->validateJobData($data);
        if ($validationResponse) {
            return $validationResponse;
        }

        $data['customer_phone_type'] = isset($data['customer_phone_type']) ? 'yes' : 'no';
        $data['customer_physical_type'] = isset($data['customer_physical_type']) ? 'yes' : 'no';

        // Determine job type based on urgency
        $dueDateTime = $this->getDueDateTime($data);
        if (is_array($dueDateTime)) {
            return $dueDateTime;
        }

        $data['due'] = $dueDateTime->format('Y-m-d H:i:s');

        // Set job characteristics based on the user's choice
        $this->setJobCharacteristics($data);

        // Set job type based on the consumer type
        $data['job_type'] = $this->determineJobType($user->userMeta->consumer_type);
        $data['b_created_at'] = now()->format('Y-m-d H:i:s');

        if (isset($data['due'])) {
            $data['will_expire_at'] = TeHelper::willExpireAt($data['due'], $data['b_created_at']);
        }

        $data['by_admin'] = $data['by_admin'] ?? 'no';

        // Create the job
        $job = $user->jobs()->create($data);

        // Format response data
        $response = $this->formatJobResponse($job, $data, $user);

        return $response;
    }

    /**
     * Validate job data to ensure all required fields are provided.
     *
     * @param array $data
     * @return array|null
     */
    private function validateJobData(array $data): ?array
    {
        $requiredFields = [
            'from_language_id' => 'Du måste fylla in alla fält',
            'duration' => 'Du måste fylla in alla fält',
        ];

        if ($data['immediate'] === 'no') {
            $requiredFields = array_merge($requiredFields, [
                'due_date' => 'Du måste fylla in alla fält',
                'due_time' => 'Du måste fylla in alla fält',
                'customer_phone_type' => 'Du måste göra ett val här',
            ]);
        }

        foreach ($requiredFields as $field => $message) {
            if (empty($data[$field])) {
                return $this->createErrorResponse($message, $field);
            }
        }

        return null;
    }

    /**
     * Determine the due date and time based on job urgency.
     *
     * @param array $data
     * @return Carbon|array
     */
    private function getDueDateTime(array $data)
    {
        if ($data['immediate'] === 'yes') {
            $dueDateTime = Carbon::now()->addMinutes($this->immediateTime);
            $data['immediate'] = 'yes';
            $data['customer_phone_type'] = 'yes';
        } else {
            $dueDateTime = Carbon::createFromFormat('m/d/Y H:i', "{$data['due_date']} {$data['due_time']}");
            if ($dueDateTime->isPast()) {
                return $this->createErrorResponse("Can't create booking in the past");
            }
        }

        return $dueDateTime;
    }

    /**
     * Set job characteristics such as gender and certification.
     *
     * @param array $data
     * @return void
     */
    private function setJobCharacteristics(array &$data): void
    {
        $data['gender'] = in_array('male', $data['job_for']) ? 'male' : (in_array('female', $data['job_for']) ? 'female' : null);

        $data['certified'] = match (true) {
            in_array('normal', $data['job_for']) && in_array('certified', $data['job_for']) => 'both',
            in_array('normal', $data['job_for']) && in_array('certified_in_law', $data['job_for']) => 'n_law',
            in_array('normal', $data['job_for']) && in_array('certified_in_helth', $data['job_for']) => 'n_health',
            in_array('certified', $data['job_for']) => 'yes',
            in_array('certified_in_law', $data['job_for']) => 'law',
            in_array('certified_in_helth', $data['job_for']) => 'health',
            default => 'normal',
        };
    }

    /**
     * Determine the job type based on the consumer type.
     *
     * @param string $consumerType
     * @return string
     */
    private function determineJobType(string $consumerType): string
    {
        return match ($consumerType) {
            'rwsconsumer' => 'rws',
            'ngo' => 'unpaid',
            'paid' => 'paid',
            default => 'unknown',
        };
    }

    /**
     * Format the response data for a newly created job.
     *
     * @param Job $job
     * @param array $data
     * @param User $user
     * @return array
     */
    private function formatJobResponse(Job $job, array $data, User $user): array
    {
        $response = [
            'status' => 'success',
            'id' => $job->id,
            'job_for' => $this->getJobForDescription($job),
            'customer_town' => $user->userMeta->city,
            'customer_type' => $user->userMeta->customer_type,
            'customer_physical_type' => $data['customer_physical_type'],
            'type' => $data['immediate'] === 'yes' ? 'immediate' : 'regular',
        ];

        return $response;
    }

    /**
     * Generate a job description based on the job characteristics.
     *
     * @param Job $job
     * @return array
     */
    private function getJobForDescription(Job $job): array
    {
        $jobFor = [];

        if ($job->gender) {
            $jobFor[] = $job->gender === 'male' ? 'Man' : 'Kvinna';
        }

        if ($job->certified) {
            $jobFor[] = match ($job->certified) {
                'both' => ['normal', 'certified'],
                'yes' => 'certified',
                default => $job->certified,
            };
        }

        return $jobFor;
    }

    /**
     * Create a standardized error response.
     *
     * @param string $message
     * @param string|null $fieldName
     * @return array
     */
    private function createErrorResponse(string $message, string $fieldName = null): array
    {
        $response = ['status' => 'fail', 'message' => $message];

        if ($fieldName) {
            $response['field_name'] = $fieldName;
        }

        return $response;
    }

    /**
     * Store job email data and send a confirmation email.
     *
     * @param array $data
     * @return array
     */
    public function storeJobEmail(array $data): array
    {
        $job = Job::findOrFail($data['user_email_job_id'] ?? null);
        $user = $job->user()->first();

        $job->user_email = $data['user_email'] ?? null;
        $job->reference = $data['reference'] ?? '';
        
        if (isset($data['address'])) {
            $job->address = !empty($data['address']) ? $data['address'] : $user->userMeta->address;
            $job->instructions = !empty($data['instructions']) ? $data['instructions'] : $user->userMeta->instructions;
            $job->town = !empty($data['town']) ? $data['town'] : $user->userMeta->city;
        }

        $job->save();

        $email = $job->user_email ?? $user->email;
        $name = $user->name;
        $subject = "Vi har mottagit er tolkbokning. Bokningsnr: #{$job->id}";
        $sendData = [
            'user' => $user,
            'job'  => $job,
        ];

        $this->mailer->send($email, $name, $subject, 'emails.job-created', $sendData);

        $response = [
            'type' => $data['user_type'],
            'job' => $job,
            'status' => 'success',
        ];

        $jobData = $this->jobToData($job);
        Event::fire(new JobWasCreated($job, $jobData, '*'));

        return $response;
    }

    /**
     * Convert job data into an array for further processing.
     *
     * @param Job $job
     * @return array
     */
    public function jobToData(Job $job): array
    {
        $jobData = [
            'job_id' => $job->id,
            'from_language_id' => $job->from_language_id,
            'immediate' => $job->immediate,
            'duration' => $job->duration,
            'status' => $job->status,
            'gender' => $job->gender,
            'certified' => $job->certified,
            'due' => $job->due,
            'job_type' => $job->job_type,
            'customer_phone_type' => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town' => $job->town,
            'customer_type' => $job->user->userMeta->customer_type,
            'due_date' => $this->extractDueDate($job->due),
            'due_time' => $this->extractDueTime($job->due),
            'job_for' => $this->getJobFor($job),
        ];

        return $jobData;
    }

    /**
     * Extract due date from a datetime string.
     *
     * @param string $due
     * @return string
     */
    private function extractDueDate(string $due): string
    {
        return explode(' ', $due)[0];
    }

    /**
     * Extract due time from a datetime string.
     *
     * @param string $due
     * @return string
     */
    private function extractDueTime(string $due): string
    {
        return explode(' ', $due)[1];
    }

    /**
     * Determine job description based on gender and certification.
     *
     * @param Job $job
     * @return array
     */
    private function getJobFor(Job $job): array
    {
        $jobFor = [];

        if ($job->gender) {
            $jobFor[] = $job->gender === 'male' ? 'Man' : 'Kvinna';
        }

        if ($job->certified) {
            $jobFor[] = match ($job->certified) {
                'both' => ['Godkänd tolk', 'Auktoriserad'],
                'yes' => 'Auktoriserad',
                'n_health' => 'Sjukvårdstolk',
                'law', 'n_law' => 'Rätttstolk',
                default => $job->certified,
            };
        }

        return is_array($jobFor[0] ?? null) ? array_merge(...$jobFor) : $jobFor;
    }

    /**
     * Mark the job as completed, send notifications, and update the translator's status.
     *
     * @param array $postData
     * @return void
     */
    public function jobEnd(array $postData): void
    {
        $completedDate = now();
        $job = Job::with('translatorJobRel')->findOrFail($postData['job_id']);
        $sessionTime = $this->calculateSessionTime($job->due, $completedDate);
        
        $job->update([
            'end_at' => $completedDate,
            'status' => 'completed',
            'session_time' => $sessionTime,
        ]);

        $this->sendJobCompletionEmail($job, $sessionTime, $job->user, 'faktura');
        $translator = $job->translatorJobRel->whereNull('completed_at')->whereNull('cancel_at')->first();

        Event::fire(new SessionEnded($job, $postData['userid'] == $job->user_id ? $translator->user_id : $job->user_id));

        $this->sendJobCompletionEmail($job, $sessionTime, $translator->user, 'lön');

        $translator->update([
            'completed_at' => $completedDate,
            'completed_by' => $postData['userid'],
        ]);
    }

    /**
     * Calculate the session time between job due date and completion date.
     *
     * @param string $dueDate
     * @param \DateTimeInterface $completedDate
     * @return string
     */
    private function calculateSessionTime(string $dueDate, \DateTimeInterface $completedDate): string
    {
        $start = date_create($dueDate);
        $end = date_create($completedDate);
        $diff = date_diff($end, $start);
        return $diff->format('%h:%i:%s');
    }

    /**
     * Send job completion email to the user or translator.
     *
     * @param Job $job
     * @param string $sessionTime
     * @param User $recipient
     * @param string $forText
     * @return void
     */
    private function sendJobCompletionEmail(Job $job, string $sessionTime, User $recipient, string $forText): void
    {
        $email = $job->user_email ?? $recipient->email;
        $subject = "Information om avslutad tolkning för bokningsnummer #{$job->id}";
        $sessionExploded = explode(':', $sessionTime);
        $formattedSessionTime = "{$sessionExploded[0]} tim {$sessionExploded[1]} min";

        $data = [
            'user' => $recipient,
            'job' => $job,
            'session_time' => $formattedSessionTime,
            'for_text' => $forText,
        ];

        $this->mailer->send($email, $recipient->name, $subject, 'emails.session-ended', $data);
    }

    /**
     * Get all potential jobs for a user based on their ID.
     *
     * @param int $userId
     * @return array
     */
    public function getPotentialJobIdsWithUserId(int $userId): array
    {
        $userMeta = UserMeta::where('user_id', $userId)->first();
        $jobType = $this->getJobTypeByTranslatorType($userMeta->translator_type);

        $userLanguages = UserLanguages::where('user_id', $userId)->pluck('lang_id')->all();
        $jobIds = Job::getJobs($userId, $jobType, 'pending', $userLanguages, $userMeta->gender, $userMeta->translator_level);

        return $this->filterJobsByTown($jobIds, $userId);
    }

    /**
     * Determine job type based on translator type.
     *
     * @param string $translatorType
     * @return string
     */
    private function getJobTypeByTranslatorType(string $translatorType): string
    {
        return match ($translatorType) {
            'professional' => 'paid',
            'rwstranslator' => 'rws',
            default => 'unpaid',
        };
    }

    /**
     * Filter jobs based on the user's town compatibility.
     *
     * @param array $jobIds
     * @param int $userId
     * @return array
     */
    private function filterJobsByTown(array $jobIds, int $userId): array
    {
        return array_filter($jobIds, function ($job) use ($userId) {
            $jobDetail = Job::find($job->id);
            $jobUserId = $jobDetail->user_id;
            return !($jobDetail->customer_phone_type === 'no' || $jobDetail->customer_phone_type === '') &&
                   $jobDetail->customer_physical_type === 'yes' &&
                   Job::checkTowns($jobUserId, $userId);
        });
    }

    /**
     * Send notifications to suitable translators.
     *
     * @param Job $job
     * @param array $data
     * @param int $excludeUserId
     * @return void
     */
    public function sendNotificationTranslator(Job $job, array $data, int $excludeUserId): void
    {
        $users = User::where('user_type', 2)
                     ->where('status', 1)
                     ->where('id', '!=', $excludeUserId)
                     ->get();

        $translatorArrays = $this->categorizeTranslators($users, $data, $job);

        $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = 'suitable_job';
        $msgText = [
            "en" => $data['immediate'] === 'no' ? 
                    "Ny bokning för {$data['language']} tolk {$data['duration']}min {$data['due']}" : 
                    "Ny akutbokning för {$data['language']} tolk {$data['duration']}min",
        ];

        $this->logPushNotification($job->id, $translatorArrays, $msgText, $data);
        $this->sendPushNotificationToSpecificUsers($translatorArrays['immediate'], $job->id, $data, $msgText, false);
        $this->sendPushNotificationToSpecificUsers($translatorArrays['delayed'], $job->id, $data, $msgText, true);
    }

    /**
     * Categorize translators into immediate and delayed push notification groups.
     *
     * @param Collection $users
     * @param array $data
     * @param Job $job
     * @return array
     */
    private function categorizeTranslators(Collection $users, array $data, Job $job): array
    {
        $immediate = [];
        $delayed = [];

        foreach ($users as $user) {
            if (!$this->isNeedToSendPush($user->id)) continue;
            if ($data['immediate'] === 'yes' && TeHelper::getUsermeta($user->id, 'not_get_emergency') === 'yes') continue;

            $jobs = $this->getPotentialJobIdsWithUserId($user->id);
            foreach ($jobs as $potentialJob) {
                if ($job->id === $potentialJob->id && Job::assignedToPaticularTranslator($user->id, $potentialJob->id) === 'SpecificJob') {
                    $jobChecker = Job::checkParticularJob($user->id, $potentialJob);
                    if ($jobChecker !== 'userCanNotAcceptJob') {
                        if ($this->isNeedToDelayPush($user->id)) {
                            $delayed[] = $user;
                        } else {
                            $immediate[] = $user;
                        }
                    }
                }
            }
        }

        return compact('immediate', 'delayed');
    }

    /**
     * Log push notifications.
     *
     * @param int $jobId
     * @param array $translatorArrays
     * @param array $msgText
     * @param array $data
     * @return void
     */
    private function logPushNotification(int $jobId, array $translatorArrays, array $msgText, array $data): void
    {
        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . now()->format('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->info('Push sent for job ' . $jobId, [$translatorArrays, $msgText, $data]);
    }

    /**
 * Sends SMS to translators and returns count of translators
 * @param Job $job
 * @return int
 */
public function sendSMSNotificationToTranslator(Job $job)
{
    $translators = $this->getPotentialTranslators($job);
    $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->first();

    $date = date('d.m.Y', strtotime($job->due));
    $time = date('H:i', strtotime($job->due));
    $duration = $this->convertToHoursMins($job->duration);
    $city = $job->city ?? $jobPosterMeta->city;

    // Determine message template based on job type
    $messageTemplate = $this->getJobMessageTemplate($job, $date, $time, $duration, $city);

    if (!$messageTemplate) {
        Log::error('Failed to generate SMS message template for job ID: ' . $job->id);
        return 0; // Return 0 if no valid message is generated
    }

    // Send SMS to each translator
    foreach ($translators as $translator) {
        $status = SendSMSHelper::send(env('SMS_NUMBER'), $translator->mobile, $messageTemplate);
        Log::info('Send SMS to ' . $translator->email . ' (' . $translator->mobile . '), status: ' . print_r($status, true));
    }

    return count($translators);
}

/**
 * Returns the appropriate SMS message template based on job type
 * @param Job $job
 * @param string $date
 * @param string $time
 * @param string $duration
 * @param string $city
 * @return string
 */
private function getJobMessageTemplate(Job $job, string $date, string $time, string $duration, string $city): string
{
    $jobId = $job->id;

    if ($job->customer_physical_type === 'yes' && $job->customer_phone_type === 'no') {
        return trans('sms.physical_job', compact('date', 'time', 'city', 'duration', 'jobId'));
    }

    return trans('sms.phone_job', compact('date', 'time', 'duration', 'jobId'));
}

/**
 * Determines if a push notification should be delayed
 * @param int $userId
 * @return bool
 */
public function isNeedToDelayPush(int $userId): bool
{
    return DateTimeHelper::isNightTime() && TeHelper::getUsermeta($userId, 'not_get_nighttime') === 'yes';
}

/**
 * Determines if a push notification should be sent
 * @param int $userId
 * @return bool
 */
public function isNeedToSendPush(int $userId): bool
{
    return TeHelper::getUsermeta($userId, 'not_get_notification') !== 'yes';
}

/**
 * Sends OneSignal push notifications to specific users
 * @param array $users
 * @param int $jobId
 * @param array $data
 * @param array $msgText
 * @param bool $isNeedDelay
 */
public function sendPushNotificationToSpecificUsers(array $users, int $jobId, array $data, array $msgText, bool $isNeedDelay): void
{
    $logger = new Logger('push_logger');
    $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
    $logger->pushHandler(new FirePHPHandler());
    $logger->addInfo('Push send for job ' . $jobId, [$users, $data, $msgText, $isNeedDelay]);

    $onesignalConfig = $this->getOneSignalConfig();
    $userTags = $this->getUserTagsStringFromArray($users);
    $iosSound = $data['notification_type'] === 'suitable_job' && $data['immediate'] === 'no' ? 'normal_booking.mp3' : 'emergency_booking.mp3';
    $androidSound = $data['notification_type'] === 'suitable_job' && $data['immediate'] === 'no' ? 'normal_booking' : 'default';

    $fields = [
        'app_id'         => $onesignalConfig['appId'],
        'tags'           => json_decode($userTags),
        'data'           => $data,
        'title'          => ['en' => 'DigitalTolk'],
        'contents'       => $msgText,
        'ios_badgeType'  => 'Increase',
        'ios_badgeCount' => 1,
        'android_sound'  => $androidSound,
        'ios_sound'      => $iosSound
    ];

    if ($isNeedDelay) {
        $fields['send_after'] = DateTimeHelper::getNextBusinessTimeString();
    }

    $fieldsJson = json_encode($fields);
    $this->sendCurlRequest("https://onesignal.com/api/v1/notifications", $onesignalConfig['restAuthKey'], $fieldsJson);
}

/**
 * Returns the OneSignal configuration based on the environment
 * @return array
 */
private function getOneSignalConfig(): array
{
    if (env('APP_ENV') === 'prod') {
        return [
            'appId' => config('app.prodOnesignalAppID'),
            'restAuthKey' => sprintf("Authorization: Basic %s", config('app.prodOnesignalApiKey'))
        ];
    }

    return [
        'appId' => config('app.devOnesignalAppID'),
        'restAuthKey' => sprintf("Authorization: Basic %s", config('app.devOnesignalApiKey'))
    ];
}

/**
 * Sends a curl request
 * @param string $url
 * @param string $authHeader
 * @param string $postFields
 */
private function sendCurlRequest(string $url, string $authHeader, string $postFields): void
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', $authHeader]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_HEADER, FALSE);
    curl_setopt($ch, CURLOPT_POST, TRUE);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
    $response = curl_exec($ch);
    Log::info('Push notification response: ' . $response);
    curl_close($ch);
}

/**
 * Get potential translators for a given job
 * @param Job $job
 * @return Collection
 */
public function getPotentialTranslators(Job $job): Collection
{
    $translatorType = $this->getTranslatorTypeBasedOnJob($job->job_type);
    $translatorLevel = $this->getTranslatorLevelBasedOnCertification($job->certified);
    $blacklistedTranslatorIds = UsersBlacklist::where('user_id', $job->user_id)->pluck('translator_id')->all();

    return User::getPotentialUsers($translatorType, $job->from_language_id, $job->gender, $translatorLevel, $blacklistedTranslatorIds);
}

/**
 * Get translator type based on job type
 * @param string $jobType
 * @return string
 */
private function getTranslatorTypeBasedOnJob(string $jobType): string
{
    return match($jobType) {
        'paid' => 'professional',
        'rws' => 'rwstranslator',
        default => 'volunteer',
    };
}

/**
 * Get translator levels based on certification
 * @param string|null $certified
 * @return array
 */
private function getTranslatorLevelBasedOnCertification(?string $certified): array
{
    return match($certified) {
        'yes', 'both' => ['Certified', 'Certified with specialisation in law', 'Certified with specialisation in health care'],
        'law', 'n_law' => ['Certified with specialisation in law'],
        'health', 'n_health' => ['Certified with specialisation in health care'],
        'normal' => ['Layman', 'Read Translation courses'],
        default => ['Certified', 'Certified with specialisation in law', 'Certified with specialisation in health care', 'Layman', 'Read Translation courses'],
    };
}

/**
 * Update job details and handle various job-related updates.
 *
 * @param int $id
 * @param array $data
 * @param User $currentUser
 * @return array
 */
public function updateJob($id, $data, $currentUser)
{
    // Retrieve the job by ID
    $job = Job::find($id);

    // Get the current translator who hasn't canceled or completed the job
    $currentTranslator = $job->translatorJobRel->where('cancel_at', null)->first();
    if (is_null($currentTranslator)) {
        $currentTranslator = $job->translatorJobRel->where('completed_at', '!=', null)->first();
    }

    $logData = [];
    $langChanged = false;

    // Handle translator change
    $changeTranslatorResult = $this->changeTranslator($currentTranslator, $data, $job);
    if ($changeTranslatorResult['translatorChanged']) {
        $logData[] = $changeTranslatorResult['log_data'];
    }

    // Handle due date change
    $changeDueResult = $this->changeDue($job->due, $data['due']);
    if ($changeDueResult['dateChanged']) {
        $oldTime = $job->due;
        $job->due = $data['due'];
        $logData[] = $changeDueResult['log_data'];
    }

    // Handle language change
    if ($job->from_language_id != $data['from_language_id']) {
        $logData[] = [
            'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
            'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
        ];
        $oldLang = $job->from_language_id;
        $job->from_language_id = $data['from_language_id'];
        $langChanged = true;
    }

    // Handle status change
    $changeStatusResult = $this->changeStatus($job, $data, $changeTranslatorResult['translatorChanged']);
    if ($changeStatusResult['statusChanged']) {
        $logData[] = $changeStatusResult['log_data'];
    }

    // Update admin comments and reference
    $job->admin_comments = $data['admin_comments'];
    $job->reference = $data['reference'];

    // Log the update made by the current user
    $this->logger->addInfo(
        'USER #' . $currentUser->id . '(' . $currentUser->name . ') updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data: ',
        $logData
    );

    // Save the job and send notifications if necessary
    if ($job->due <= Carbon::now()) {
        $job->save();
        return ['Updated'];
    } else {
        $job->save();
        if ($changeDueResult['dateChanged']) {
            $this->sendChangedDateNotification($job, $oldTime);
        }
        if ($changeTranslatorResult['translatorChanged']) {
            $this->sendChangedTranslatorNotification($job, $currentTranslator, $changeTranslatorResult['new_translator']);
        }
        if ($langChanged) {
            $this->sendChangedLangNotification($job, $oldLang);
        }
    }
}

/**
 * Change the status of the job based on the provided data and translator change.
 *
 * @param Job $job
 * @param array $data
 * @param bool $translatorChanged
 * @return array
 */
private function changeStatus($job, $data, $translatorChanged)
{
    $oldStatus = $job->status;
    $statusChanged = false;

    if ($oldStatus != $data['status']) {
        switch ($oldStatus) {
            case 'timedout':
                $statusChanged = $this->changeTimedoutStatus($job, $data, $translatorChanged);
                break;
            case 'completed':
                $statusChanged = $this->changeCompletedStatus($job, $data);
                break;
            case 'started':
                $statusChanged = $this->changeStartedStatus($job, $data);
                break;
            case 'pending':
                $statusChanged = $this->changePendingStatus($job, $data, $translatorChanged);
                break;
            case 'withdrawafter24':
                $statusChanged = $this->changeWithdrawafter24Status($job, $data);
                break;
            case 'assigned':
                $statusChanged = $this->changeAssignedStatus($job, $data);
                break;
            default:
                $statusChanged = false;
                break;
        }

        if ($statusChanged) {
            $logData = [
                'old_status' => $oldStatus,
                'new_status' => $data['status']
            ];
            return ['statusChanged' => true, 'log_data' => $logData];
        }
    }

    return ['statusChanged' => false];
}

/**
 * Handle the status change when the job is timed out.
 *
 * @param Job $job
 * @param array $data
 * @param bool $translatorChanged
 * @return bool
 */
private function changeTimedoutStatus($job, $data, $translatorChanged)
{
    $oldStatus = $job->status;
    $job->status = $data['status'];

    // Get the user and email details
    $user = $job->user()->first();
    $email = $job->user_email ?? $user->email;
    $name = $user->name;
    $dataEmail = [
        'user' => $user,
        'job' => $job
    ];

    // If the status is changed to 'pending'
    if ($data['status'] == 'pending') {
        $job->created_at = now();
        $job->emailsent = 0;
        $job->emailsenttovirpal = 0;
        $job->save();

        $jobData = $this->jobToData($job);
        $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
        $this->mailer->send($email, $name, $subject, 'emails.job-change-status-to-customer', $dataEmail);

        // Send push notifications to all suitable translators
        $this->sendNotificationTranslator($job, $jobData, '*');

        return true;
    } elseif ($translatorChanged) {
        $job->save();
        $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
        $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);
        return true;
    }

    return false;
}

/**
 * Handle the status change when the job is completed.
 *
 * @param Job $job
 * @param array $data
 * @return bool
 */
private function changeCompletedStatus($job, $data)
{
    $job->status = $data['status'];

    // If the status is changed to 'timedout' and admin comments are provided
    if ($data['status'] == 'timedout' && !empty($data['admin_comments'])) {
        $job->admin_comments = $data['admin_comments'];
        $job->save();
        return true;
    }

    return false;
}

/**
 * Update the status of a job to 'Started'.
 *
 * @param $job  The job object to be updated.
 * @param $data An associative array containing job data.
 * @return bool Returns true if the job status is successfully updated, otherwise false.
 */
private function changeStartedStatus($job, $data)
{
    // Update job status
    $job->status = $data['status'];

    // Ensure admin comments are provided
    if (empty($data['admin_comments'])) return false;
    $job->admin_comments = $data['admin_comments'];

    // Handle the case where the status is 'completed'
    if ($data['status'] == 'completed') {
        $user = $job->user()->first();

        // Ensure session time is provided
        if (empty($data['sesion_time'])) return false;
        $interval = $data['sesion_time'];
        $diff = explode(':', $interval);

        // Update job end time and session duration
        $job->end_at = date('Y-m-d H:i:s');
        $job->session_time = $interval;
        $session_time = $diff[0] . ' hours ' . $diff[1] . ' minutes';

        // Determine the email address to send the notification
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job' => $job,
            'session_time' => $session_time,
            'for_text' => 'invoice'
        ];

        // Send completion notification to the customer
        $subject = 'Session Completed for Job #' . $job->id;
        $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

        // Send notification to the translator
        $translator = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();
        $translatorEmail = $translator->user->email;
        $translatorName = $translator->user->name;
        $this->mailer->send($translatorEmail, $translatorName, $subject, 'emails.session-ended', $dataEmail);
    }

    // Save the updated job object
    $job->save();
    return true;
}

/**
 * Update the status of a job to 'Pending'.
 *
 * @param $job  The job object to be updated.
 * @param $data An associative array containing job data.
 * @param $changedTranslator Boolean indicating if the translator was changed.
 * @return bool Returns true if the job status is successfully updated, otherwise false.
 */
private function changePendingStatus($job, $data, $changedTranslator)
{
    // Update job status
    $job->status = $data['status'];

    // Ensure admin comments are provided if status is 'timedout'
    if ($data['status'] == 'timedout' && empty($data['admin_comments'])) return false;
    $job->admin_comments = $data['admin_comments'];

    // Determine the email address to send the notification
    $user = $job->user()->first();
    $email = !empty($job->user_email) ? $job->user_email : $user->email;
    $name = $user->name;
    $dataEmail = [
        'user' => $user,
        'job' => $job
    ];

    // Handle the case where the status is 'assigned' and translator was changed
    if ($data['status'] == 'assigned' && $changedTranslator) {
        $job->save();
        $job_data = $this->jobToData($job);

        // Send notification to the customer and translator
        $subject = 'Confirmation - Translator Accepted Your Booking (#' . $job->id . ')';
        $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);

        // Send session start reminder notifications
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $this->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
        $this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);

        return true;
    } else {
        // Send cancellation notification
        $subject = 'Booking Cancelled: #' . $job->id;
        $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
        $job->save();
        return true;
    }
}

/**
 * Update the status of a job to 'Withdrawn After 24 Hours'.
 *
 * @param $job  The job object to be updated.
 * @param $data An associative array containing job data.
 * @return bool Returns true if the job status is successfully updated, otherwise false.
 */
private function changeWithdrawafter24Status($job, $data)
{
    // Update job status to 'timedout'
    if ($data['status'] == 'timedout') {
        if (empty($data['admin_comments'])) return false;
        $job->status = $data['status'];
        $job->admin_comments = $data['admin_comments'];
        $job->save();
        return true;
    }
    return false;
}

/**
 * Update the status of a job to 'Assigned'.
 *
 * @param $job  The job object to be updated.
 * @param $data An associative array containing job data.
 * @return bool Returns true if the job status is successfully updated, otherwise false.
 */
private function changeAssignedStatus($job, $data)
{
    // Check if the new status is among specific statuses
    if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24', 'timedout'])) {
        // Update job status
        $job->status = $data['status'];

        // Ensure admin comments are provided if status is 'timedout'
        if ($data['status'] == 'timedout' && empty($data['admin_comments'])) return false;
        $job->admin_comments = $data['admin_comments'];

        // Handle case when job is withdrawn
        if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
            $user = $job->user()->first();

            // Determine the email address to send the notification
            $email = !empty($job->user_email) ? $job->user_email : $user->email;
            $name = $user->name;
            $dataEmail = [
                'user' => $user,
                'job' => $job
            ];

            // Send job cancellation notification to the customer
            $subject = 'Job Completed for Booking #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

            // Send job cancellation notification to the translator
            $translator = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();
            $translatorEmail = $translator->user->email;
            $translatorName = $translator->user->name;
            $this->mailer->send($translatorEmail, $translatorName, $subject, 'emails.job-cancel-translator', $dataEmail);
        }

        // Save the updated job object
        $job->save();
        return true;
    }
    return false;
}

/**
 * Change the translator for a job, if necessary, and log the changes.
 *
 * @param Translator|null $current_translator The current translator assigned to the job.
 * @param array $data The data containing the new translator details.
 * @param Job $job The job for which the translator is being changed.
 * @return array An array containing whether the translator was changed, and any log data.
 */
private function changeTranslator($current_translator, $data, $job)
{
    $translatorChanged = false;
    $log_data = [];

    // Check if the translator needs to be changed
    if (!is_null($current_translator) || isset($data['translator']) || $data['translator_email'] != '') {
        if (!is_null($current_translator) &&
            (isset($data['translator']) && $current_translator->user_id != $data['translator']) ||
            $data['translator_email'] != '') {

            // Assign new translator based on email or ID
            if ($data['translator_email'] != '') {
                $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
            }
            $new_translator = $current_translator->toArray();
            $new_translator['user_id'] = $data['translator'];
            unset($new_translator['id']);
            $new_translator = Translator::create($new_translator);

            // Cancel the current translator
            $current_translator->cancel_at = Carbon::now();
            $current_translator->save();

            $log_data[] = [
                'old_translator' => $current_translator->user->email,
                'new_translator' => $new_translator->user->email
            ];
            $translatorChanged = true;

        } elseif (is_null($current_translator) && isset($data['translator']) && ($data['translator'] != 0 || $data['translator_email'] != '')) {

            // Assign a new translator when none was previously assigned
            if ($data['translator_email'] != '') {
                $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
            }
            $new_translator = Translator::create(['user_id' => $data['translator'], 'job_id' => $job->id]);
            $log_data[] = [
                'old_translator' => null,
                'new_translator' => $new_translator->user->email
            ];
            $translatorChanged = true;
        }

        if ($translatorChanged) {
            return ['translatorChanged' => $translatorChanged, 'new_translator' => $new_translator, 'log_data' => $log_data];
        }
    }

    return ['translatorChanged' => $translatorChanged];
}

/**
 * Change the due date/time of a job, if necessary, and log the changes.
 *
 * @param string $old_due The original due date/time.
 * @param string $new_due The new due date/time.
 * @return array An array containing whether the date was changed, and any log data.
 */
private function changeDue($old_due, $new_due)
{
    $dateChanged = false;
    $log_data = [];

    if ($old_due != $new_due) {
        $log_data = [
            'old_due' => $old_due,
            'new_due' => $new_due
        ];
        $dateChanged = true;
    }

    return ['dateChanged' => $dateChanged, 'log_data' => $log_data];
}

/**
 * Send notifications about the change in the assigned translator.
 *
 * @param Job $job The job for which the translator was changed.
 * @param Translator|null $current_translator The previous translator, if any.
 * @param Translator $new_translator The new translator assigned to the job.
 */
public function sendChangedTranslatorNotification($job, $current_translator, $new_translator)
{
    $user = $job->user()->first();
    $email = !empty($job->user_email) ? $job->user_email : $user->email;
    $name = $user->name;
    $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag # ' . $job->id;
    $data = ['user' => $user, 'job' => $job];

    // Notify the customer
    $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-customer', $data);

    // Notify the previous translator, if any
    if ($current_translator) {
        $this->mailer->send($current_translator->user->email, $current_translator->user->name, $subject, 'emails.job-changed-translator-old-translator', $data);
    }

    // Notify the new translator
    $this->mailer->send($new_translator->user->email, $new_translator->user->name, $subject, 'emails.job-changed-translator-new-translator', $data);
}

/**
 * Send notifications about the change in the job's due date/time.
 *
 * @param Job $job The job for which the date/time was changed.
 * @param string $old_time The original date/time.
 */
public function sendChangedDateNotification($job, $old_time)
{
    $user = $job->user()->first();
    $email = !empty($job->user_email) ? $job->user_email : $user->email;
    $name = $user->name;
    $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id;
    $data = ['user' => $user, 'job' => $job, 'old_time' => $old_time];

    // Notify the customer
    $this->mailer->send($email, $name, $subject, 'emails.job-changed-date', $data);

    // Notify the translator
    $translator = Job::getJobsAssignedTranslatorDetail($job);
    $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
}

/**
 * Send notifications about the change in the job's language.
 *
 * @param Job $job The job for which the language was changed.
 * @param string $old_lang The original language.
 */
public function sendChangedLangNotification($job, $old_lang)
{
    $user = $job->user()->first();
    $email = !empty($job->user_email) ? $job->user_email : $user->email;
    $name = $user->name;
    $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id;
    $data = ['user' => $user, 'job' => $job, 'old_lang' => $old_lang];

    // Notify the customer
    $this->mailer->send($email, $name, $subject, 'emails.job-changed-lang', $data);

    // Notify the translator
    $translator = Job::getJobsAssignedTranslatorDetail($job);
    $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
}

/**
 * Send a push notification when a job expires without being accepted.
 *
 * @param Job $job The job that has expired.
 * @param User $user The user who created the job.
 */
public function sendExpiredNotification($job, $user)
{
    $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
    $msg_text = [
        "en" => 'Tyvärr har ingen tolk accepterat er bokning: (' . $language . ', ' . $job->duration . 'min, ' . $job->due . '). Vänligen pröva boka om tiden.'
    ];

    // Send push notification if necessary
    if ($this->isNeedToSendPush($user->id)) {
        $users_array = [$user];
        $this->sendPushNotificationToSpecificUsers($users_array, $job->id, ['notification_type' => 'job_expired'], $msg_text, $this->isNeedToDelayPush($user->id));
    }
}

/**
 * Sends notification to translators when an admin cancels a job.
 *
 * @param int $job_id The ID of the job being canceled.
 */
public function sendNotificationByAdminCancelJob($job_id)
{
    $job = Job::findOrFail($job_id);
    $user_meta = $job->user->userMeta()->first();

    // Prepare data for push notification
    $data = [
        'job_id' => $job->id,
        'from_language_id' => $job->from_language_id,
        'immediate' => $job->immediate,
        'duration' => $job->duration,
        'status' => $job->status,
        'gender' => $job->gender,
        'certified' => $job->certified,
        'due' => $job->due,
        'job_type' => $job->job_type,
        'customer_phone_type' => $job->customer_phone_type,
        'customer_physical_type' => $job->customer_physical_type,
        'customer_town' => $user_meta->city,
        'customer_type' => $user_meta->customer_type,
    ];

    // Split due date and time
    [$due_date, $due_time] = explode(" ", $job->due);
    $data['due_date'] = $due_date;
    $data['due_time'] = $due_time;

    // Determine job requirements based on gender and certification
    $data['job_for'] = [];
    if ($job->gender) {
        $data['job_for'][] = ($job->gender == 'male') ? 'Man' : 'Kvinna';
    }
    if ($job->certified) {
        if ($job->certified == 'both') {
            $data['job_for'][] = 'normal';
            $data['job_for'][] = 'certified';
        } else {
            $data['job_for'][] = $job->certified == 'yes' ? 'certified' : $job->certified;
        }
    }

    // Send push notification to all suitable translators
    $this->sendNotificationTranslator($job, $data, '*');
}

/**
 * Sends a session start reminder notification to the user.
 *
 * @param User $user The user to be notified.
 * @param Job $job The job related to the session.
 * @param string $language The language of the job.
 * @param string $due The due date and time of the session.
 * @param string $duration The duration of the session.
 */
private function sendNotificationChangePending($user, $job, $language, $due, $duration)
{
    $data = ['notification_type' => 'session_start_remind'];

    // Prepare message text based on job type
    $msg_text = [
        "en" => 'Du har nu fått ' . 
                ($job->customer_physical_type == 'yes' ? 'platstolkningen' : 'telefontolkningen') . 
                ' för ' . $language . ' kl ' . $duration . ' den ' . $due . 
                '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
    ];

    // Send push notification if required
    if ($this->bookingRepository->isNeedToSendPush($user->id)) {
        $this->bookingRepository->sendPushNotificationToSpecificUsers(
            [$user], $job->id, $data, $msg_text, 
            $this->bookingRepository->isNeedToDelayPush($user->id)
        );
    }
}

/**
 * Converts an array of users into a OneSignal user tags string.
 *
 * @param array $users The users to be converted.
 * @return string The resulting user tags string.
 */
private function getUserTagsStringFromArray($users)
{
    $user_tags = "[";

    foreach ($users as $index => $oneUser) {
        $user_tags .= ($index > 0 ? ',{"operator": "OR"},' : '') .
                      '{"key": "email", "relation": "=", "value": "' . strtolower($oneUser->email) . '"}';
    }

    $user_tags .= ']';
    return $user_tags;
}

/**
 * Accepts a job for a user.
 *
 * @param array $data The data containing the job ID.
 * @param User $user The user accepting the job.
 * @return array The response status and related messages.
 */
public function acceptJob($data, $user)
{
    $job_id = $data['job_id'];
    $job = Job::findOrFail($job_id);

    if (!Job::isTranslatorAlreadyBooked($job_id, $user->id, $job->due)) {
        if ($job->status == 'pending' && Job::insertTranslatorJobRel($user->id, $job_id)) {
            $job->status = 'assigned';
            $job->save();

            $this->notifyJobAccepted($job, $user);

            return [
                'status' => 'success',
                'list' => json_encode(['jobs' => $this->getPotentialJobs($user), 'job' => $job], true),
            ];
        }

        return ['status' => 'fail', 'message' => 'Du har redan en bokning den tiden! Bokningen är inte accepterad.'];
    }

    return ['status' => 'fail', 'message' => 'Du har redan en bokning den tiden! Bokningen är inte accepterad.'];
}

/**
 * Accepts a job by its ID for a specific user.
 *
 * @param int $job_id The ID of the job to accept.
 * @param User $cuser The user accepting the job.
 * @return array The response status and related messages.
 */
public function acceptJobWithId($job_id, $cuser)
{
    $job = Job::findOrFail($job_id);

    if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
        if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
            $job->status = 'assigned';
            $job->save();

            $this->notifyJobAccepted($job, $cuser);

            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
            return [
                'status' => 'success',
                'list' => ['job' => $job],
                'message' => 'Du har nu accepterat och fått bokningen för ' . $language . 'tolk ' . $job->duration . 'min ' . $job->due,
            ];
        }

        return $this->failJobAcceptance($job, 'Denna tolkning har redan accepterats av annan tolk.');
    }

    return $this->failJobAcceptance($job, 'Du har redan en bokning den tiden.');
}

/**
 * Notify the user and customer about job acceptance.
 *
 * @param Job $job The accepted job.
 * @param User $cuser The user who accepted the job.
 */
private function notifyJobAccepted($job, $cuser)
{
    $user = $job->user()->first();
    $email = !empty($job->user_email) ? $job->user_email : $user->email;
    $name = $user->name;
    $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';

    $mailer = new AppMailer();
    $mailer->send($email, $name, $subject, 'emails.job-accepted', ['user' => $user, 'job' => $job]);

    $this->sendPushNotification($user, $job, 'Din bokning har accepterats av en tolk.');
}

/**
 * Sends a failure message for job acceptance.
 *
 * @param Job $job The job that failed to be accepted.
 * @param string $message The failure message.
 * @return array The failure response.
 */
private function failJobAcceptance($job, $message)
{
    $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
    return [
        'status' => 'fail',
        'message' => $message . ' ' . $job->duration . 'min ' . $job->due . '. Du har inte fått denna tolkning',
    ];
}

/**
 * Sends push notification to the user.
 *
 * @param User $user The user to notify.
 * @param Job $job The job associated with the notification.
 * @param string $message The message to send.
 */
private function sendPushNotification($user, $job, $message)
{
    $data = ['notification_type' => 'job_accepted'];
    $msg_text = ["en" => $message];

    if ($this->isNeedToSendPush($user->id)) {
        $this->sendPushNotificationToSpecificUsers(
            [$user], $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id)
        );
    }
}

/**
     * Handle job cancellation via AJAX.
     *
     * @param array $data
     * @param User $user
     * @return array
     */
    public function cancelJobAjax(array $data, $user)
    {
        $response = [];
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);
        $translator = Job::getJobsAssignedTranslatorDetail($job);

        // Check if user is a customer
        if ($user->is('customer')) {
            $job->withdraw_at = Carbon::now();

            // Determine the status based on withdrawal time
            if ($job->withdraw_at->diffInHours($job->due) >= 24) {
                $job->status = 'withdrawbefore24';
            } else {
                $job->status = 'withdrawafter24';
                // Handle additional actions for cancellations within 24 hours
                $this->handleCancellationWithin24Hours($job, $translator);
            }

            $job->save();
            Event::fire(new JobWasCanceled($job));

            $response['status'] = 'success';
            $response['jobstatus'] = 'success';

        } else {
            // Handle cancellation by other types of users
            $this->handleNonCustomerCancellation($job, $translator, $response);
        }

        return $response;
    }

    /**
     * Handle cancellation within 24 hours.
     *
     * @param Job $job
     * @param Translator|null $translator
     * @return void
     */
    protected function handleCancellationWithin24Hours($job, $translator)
    {
        if ($translator) {
            $data = [
                'notification_type' => 'job_cancelled',
                'msg_text' => [
                    'en' => 'Kunden har avbokat bokningen för ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) .
                        'tolk, ' . $job->duration . 'min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
                ]
            ];

            if ($this->isNeedToSendPush($translator->id)) {
                $this->sendPushNotificationToSpecificUsers(
                    [$translator],
                    $job->id,
                    $data,
                    $this->isNeedToDelayPush($translator->id)
                );
            }
        }
    }

    /**
     * Handle cancellation by non-customer users.
     *
     * @param Job $job
     * @param Translator|null $translator
     * @param array $response
     * @return void
     */
    protected function handleNonCustomerCancellation($job, $translator, &$response)
    {
        if ($job->due->diffInHours(Carbon::now()) > 24) {
            $this->notifyCustomer($job);
            $this->resetJobStatus($job);
            Job::deleteTranslatorJobRel($translator->id, $job->id);

            $data = $this->jobToData($job);
            $this->sendNotificationTranslator($job, $data, $translator->id);

            $response['status'] = 'success';
        } else {
            $response['status'] = 'fail';
            $response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning över telefon. Tack!';
        }
    }

    /**
     * Notify the customer about job cancellation.
     *
     * @param Job $job
     * @return void
     */
    protected function notifyCustomer($job)
    {
        $customer = $job->user()->first();
        if ($customer) {
            $data = [
                'notification_type' => 'job_cancelled',
                'msg_text' => [
                    'en' => 'Er ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) .
                        'tolk, ' . $job->duration . 'min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
                ]
            ];

            if ($this->isNeedToSendPush($customer->id)) {
                $this->sendPushNotificationToSpecificUsers(
                    [$customer],
                    $job->id,
                    $data,
                    $this->isNeedToDelayPush($customer->id)
                );
            }
        }
    }

    /**
     * Reset the job status to 'pending' and update job attributes.
     *
     * @param Job $job
     * @return void
     */
    protected function resetJobStatus($job)
    {
        $job->status = 'pending';
        $job->created_at = Carbon::now();
        $job->will_expire_at = TeHelper::willExpireAt($job->due, Carbon::now());
        $job->save();
    }

    /**
     * End a job and handle related notifications.
     *
     * @param array $post_data
     * @return array
     */
    public function endJob(array $post_data)
    {
        $completeddate = Carbon::now();
        $jobid = $post_data["job_id"];
        $job = Job::with('translatorJobRel')->find($jobid);

        if ($job->status != 'started') {
            return ['status' => 'success'];
        }

        $interval = $this->calculateSessionTime($job->due, $completeddate);

        $this->updateJobAndSendNotifications($job, $post_data['user_id'], $interval, $completeddate);

        return ['status' => 'success'];
    }

    /**
     * Calculate the session time in H:i:s format.
     *
     * @param Carbon $duedate
     * @param Carbon $completeddate
     * @return string
     */
    protected function calculateSessionTime($duedate, $completeddate)
    {
        $start = $duedate;
        $diff = $completeddate->diff($start);
        return sprintf('%02d:%02d:%02d', $diff->h, $diff->i, $diff->s);
    }

    /**
     * Update job details and send notification emails.
     *
     * @param Job $job
     * @param int $user_id
     * @param string $interval
     * @param Carbon $completeddate
     * @return void
     */
    protected function updateJobAndSendNotifications($job, $user_id, $interval, $completeddate)
    {
        $job->end_at = $completeddate;
        $job->status = 'completed';
        $job->session_time = $interval;
        $job->save();

        $this->sendEndJobEmails($job, $user_id);
        $this->updateTranslatorJobRel($job, $completeddate, $user_id);
    }

    /**
     * Send email notifications about the ended job.
     *
     * @param Job $job
     * @param int $user_id
     * @return void
     */
    protected function sendEndJobEmails($job, $user_id)
    {
        $user = $job->user()->first();
        $translator = $job->translatorJobRel()->whereNull('completed_at')->whereNull('cancel_at')->first();

        // Email to customer
        $this->sendEmailNotification($user->email, $user->name, 'Information om avslutad tolkning för bokningsnummer # ' . $job->id, 'faktura', $job);

        // Email to translator
        if ($translator) {
            $this->sendEmailNotification($translator->user->email, $translator->user->name, 'Information om avslutad tolkning för bokningsnummer # ' . $job->id, 'lön', $job);
        }
    }

    /**
     * Send email notification.
     *
     * @param string $email
     * @param string $name
     * @param string $subject
     * @param string $for_text
     * @param Job $job
     * @return void
     */
    protected function sendEmailNotification($email, $name, $subject, $for_text, $job)
    {
        $session_explode = explode(':', $job->session_time);
        $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';
        $data = [
            'user' => $user,
            'job' => $job,
            'session_time' => $session_time,
            'for_text' => $for_text
        ];

        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);
    }

    /**
     * Update translator job relationship details.
     *
     * @param Job $job
     * @param Carbon $completeddate
     * @param int $user_id
     * @return void
     */
    protected function updateTranslatorJobRel($job, $completeddate, $user_id)
    {
        $translatorJobRel = $job->translatorJobRel()->whereNull('completed_at')->whereNull('cancel_at')->first();
        if ($translatorJobRel) {
            $translatorJobRel->completed_at = $completeddate;
            $translatorJobRel->save();
        }
    }

     /**
     * Retrieve alerts for jobs based on certain conditions.
     *
     * @return array
     */
    public function alerts()
    {
        // Fetch all jobs
        $jobs = Job::all();
        $sesJobs = [];
        $jobId = [];
        $diff = [];
        $i = 0;

        // Process each job
        foreach ($jobs as $job) {
            $sessionTime = explode(':', $job->session_time);

            // Calculate session duration in hours
            if (count($sessionTime) >= 3) {
                $diff[$i] = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);

                // Filter jobs based on duration
                if ($diff[$i] >= $job->duration) {
                    if ($diff[$i] >= $job->duration * 2) {
                        $sesJobs[$i] = $job;
                    }
                }
                $i++;
            }
        }

        // Extract job IDs for filtered jobs
        foreach ($sesJobs as $job) {
            $jobId[] = $job->id;
        }

        // Fetch necessary data for filtering
        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestdata = Request::all();
        $all_customers = DB::table('users')->where('user_type', '1')->pluck('email');
        $all_translators = DB::table('users')->where('user_type', '2')->pluck('email');

        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');

        if ($cuser && $cuser->is('superadmin')) {
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
                ->whereIn('jobs.id', $jobId);

            // Apply filters based on request data
            $this->applyJobFilters($allJobs, $requestdata);

            $allJobs->select('jobs.*', 'languages.language')
                ->where('jobs.ignore', 0)
                ->orderBy('jobs.created_at', 'desc');

            // Paginate results
            $allJobs = $allJobs->paginate(15);
        }

        return [
            'allJobs' => $allJobs,
            'languages' => $languages,
            'all_customers' => $all_customers,
            'all_translators' => $all_translators,
            'requestdata' => $requestdata
        ];
    }

    /**
     * Apply filters to the job query.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param array $requestdata
     * @return void
     */
    protected function applyJobFilters($query, $requestdata)
    {
        if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
            $query->whereIn('jobs.from_language_id', $requestdata['lang']);
        }

        if (isset($requestdata['status']) && $requestdata['status'] != '') {
            $query->whereIn('jobs.status', $requestdata['status']);
        }

        if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
            $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
            if ($user) {
                $query->where('jobs.user_id', $user->id);
            }
        }

        if (isset($requestdata['translator_email']) && $requestdata['translator_email'] != '') {
            $user = DB::table('users')->where('email', $requestdata['translator_email'])->first();
            if ($user) {
                $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->pluck('job_id');
                $query->whereIn('jobs.id', $allJobIDs);
            }
        }

        if (isset($requestdata['filter_timetype'])) {
            $this->applyDateFilters($query, $requestdata);
        }

        if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
            $query->whereIn('jobs.job_type', $requestdata['job_type']);
        }
    }

    /**
     * Apply date filters to the job query.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param array $requestdata
     * @return void
     */
    protected function applyDateFilters($query, $requestdata)
    {
        $dateColumn = $requestdata['filter_timetype'] === 'created' ? 'created_at' : 'due';

        if (isset($requestdata['from']) && $requestdata['from'] != "") {
            $query->where($dateColumn, '>=', $requestdata['from']);
        }

        if (isset($requestdata['to']) && $requestdata['to'] != "") {
            $to = $requestdata['to'] . " 23:59:00";
            $query->where($dateColumn, '<=', $to);
        }

        $query->where('jobs.ignore', 0);
    }

    /**
     * Retrieve failed user logins.
     *
     * @return array
     */
    public function userLoginFailed()
    {
        $throttles = Throttles::where('ignore', 0)->with('user')->paginate(15);
        return ['throttles' => $throttles];
    }

    /**
     * Retrieve bookings that have expired and were not accepted.
     *
     * @return array
     */
    public function bookingExpireNoAccepted()
    {
        // Fetch necessary data for filtering
        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestdata = Request::all();
        $all_customers = DB::table('users')->where('user_type', '1')->pluck('email');
        $all_translators = DB::table('users')->where('user_type', '2')->pluck('email');

        $cuser = Auth::user();

        if ($cuser && ($cuser->is('superadmin') || $cuser->is('admin'))) {
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
                ->where('jobs.ignore_expired', 0)
                ->where('jobs.status', 'pending')
                ->where('jobs.due', '>=', Carbon::now());

            // Apply filters based on request data
            $this->applyJobFilters($allJobs, $requestdata);

            $allJobs->select('jobs.*', 'languages.language')
                ->orderBy('jobs.created_at', 'desc');

            // Paginate results
            $allJobs = $allJobs->paginate(15);
        }

        return [
            'allJobs' => $allJobs,
            'languages' => $languages,
            'all_customers' => $all_customers,
            'all_translators' => $all_translators,
            'requestdata' => $requestdata
        ];
    }

}