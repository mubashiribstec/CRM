<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Horsefly\Sale;
use Horsefly\Unit;
use Horsefly\Message;
use Horsefly\EmailTemplate;
use Horsefly\Applicant;
use App\Http\Controllers\Controller;
use Yajra\DataTables\Facades\DataTables;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Builder;
use Exception;
use Carbon\Carbon;
use Horsefly\JobCategory;
use Horsefly\User;
use Horsefly\SentEmail;
use App\Traits\SendEmails;
use App\Traits\SendSMS;
use Horsefly\Contact;
use Illuminate\Support\Str;

class CommunicationController extends Controller
{
    use SendEmails, SendSMS;

    public function __construct()
    {
        //
    }
    public function index()
    {
        return view('emails.compose-email');
    }
    public function Messagesindex()
    {
        return view('messages.index');
    }
    public function sentEmailsIndex()
    {
        return view('emails.sent-emails');
    }
    public function writeMessageindex()
    {
        return view('messages.write-message');
    }
    public function sendEmailsToApplicants(Request $request)
    {
        $radius = 15; //kilometers
        $id = $request->sale_id;
        $sale = Sale::find($id);
        $unit = Unit::where('office_id', $sale->office_id)->first();
        $JobCategory = JobCategory::where('id', $sale->job_category_id)->first();
        $job_category = $sale->job_category_id;
        $job_postcode = $sale->sale_postcode;
        $job_title = $sale->job_title_id;

        $nearby_applicants = $this->distance($sale->lat, $sale->lng, $radius, $job_title, $job_category);
        $emails = is_null($nearby_applicants) ? '' : implode(',', $nearby_applicants->toArray());

        $user_name = Auth::user()->name;

        $category = ($JobCategory ? ucwords($JobCategory->name) : '-');
        $unit_name = ($unit ? $unit->unit_name : '-');
        $salary = $sale->salary ?? '-';
        $qualification = $sale->qualification ?? '-';
        $job_type = $sale->position_type ?? '-';
        $timing = $sale->timing ?? '-';
        $experience = $sale->experience ?? '-';
        $location = '-';

        // Fill template placeholders
        $replace = [
            $user_name,
            optional($JobCategory)->name ?? '-',
            optional($unit)->unit_name ?? '-',
            $sale->salary ?? '-',
            $sale->qualification ?? '-',
            $sale->position_type ?? '-',
            $sale->timing ?? '-',
            $sale->experience ?? '-',
            '-',
        ];
        $prev_val = ['(agent_name)', '(job_category)', '(unit_name)', '(salary)', '(qualification)', '(job_type)', '(timing)', '(experience)', '(location)'];

        $formattedMessage = '';
        $subject = '';

        $template = EmailTemplate::where('slug', 'send_job_vacancy_details')->where('is_active', 1)->first();
        if ($template && !empty($template->template)) {
            $newPhrase = str_replace($prev_val, $replace, $template->template);
            $formattedMessage = nl2br($newPhrase);
            $subject = $template->subject;
        }

        return view('emails.send-email-to-applicant', compact('sale', 'unit', 'subject', 'formattedMessage', 'emails'));
    }
    function distance($lat, $lon, $radius, $job_title_id, $job_category_id)
    {
        $location_distance = Applicant::with('cv_notes')
            ->select(DB::raw("
                id,
                lat,
                lng,
                applicant_email,
                applicant_email_secondary,
                ((ACOS(SIN($lat * PI() / 180) * SIN(lat * PI() / 180) + 
                COS($lat * PI() / 180) * COS(lat * PI() / 180) * COS(($lon - lng) * PI() / 180)) * 180 / PI()) * 60 * 1.852) AS distance
            "))
            ->having("distance", "<", $radius)
            ->orderBy("distance")
            ->where('applicants.status', 1)
            ->where('applicants.is_in_nurse_home', false)
            ->where('applicants.is_blocked', false)
            ->where('applicants.is_callback_enable', false)
            ->where('applicants.is_no_job', false)
            ->where("job_category_id", $job_category_id)
            ->orWhere("job_title_id", $job_title_id)
            ->whereNotNull('applicant_email')
            ->get();

        if ($location_distance->isEmpty()) {
            return null;
        }

        $validDomains = ['.com', '.msn', '.net', '.uk', '.gr'];

        $validEmailAddresses = $location_distance->flatMap(function ($applicant) use ($validDomains) {
            return collect([
                $applicant->applicant_email,
                $applicant->applicant_email_secondary
            ])
                ->filter() // Remove nulls
                ->unique() // Remove duplicates
                ->filter(function ($email) use ($validDomains) {
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
                    if (preg_match('/^[A-Za-z0-9._%+-]+@example\.com$/', $email)) return false;
                    if (strpos($email, '@') === false) return false;

                    foreach ($validDomains as $domain) {
                        if (str_ends_with($email, $domain)) {
                            return true;
                        }
                    }

                    return false;
                });
        })->unique()->values();

        return $validEmailAddresses;
    }
    public function getSentEmailsAjaxRequest(Request $request)
    {
        $searchTerm = $request->input('search', ''); // This will get the search query

        $model = SentEmail::query();

        // Sorting logic
        if ($request->has('order')) {
            $orderColumn = $request->input('columns.' . $request->input('order.0.column') . '.data');
            $orderDirection = $request->input('order.0.dir', 'asc');

            // Handle special cases first
            if ($orderColumn && $orderColumn !== 'DT_RowIndex') {
                $model->orderBy($orderColumn, $orderDirection);
            }
            // Fallback if no valid order column is found
            else {
                $model->orderBy('sent_emails.updated_at', 'desc');
            }
        } else {
            // Default sorting when no order is specified
            $model->orderBy('sent_emails.updated_at', 'desc');
        }

        if ($request->has('search.value')) {
            $searchTerm = (string) $request->input('search.value');

            if (!empty($searchTerm)) {
                $model->where(function ($query) use ($searchTerm) {
                    // Direct column searches
                    $query->where('sent_emails.sent_to', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('sent_emails.sent_from', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('sent_emails.title', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('sent_emails.cc_emails', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('sent_emails.subject', 'LIKE', "%{$searchTerm}%");
                });
            }
        }

        if ($request->ajax()) {
            return DataTables::eloquent($model)
                ->addIndexColumn() // This will automatically add a serial number to the rows
                ->addColumn('updated_at', function ($email) {
                    return Carbon::parse($email->updated_at)->format('d M Y, h:iA');
                })
                ->addColumn('action', function ($email) {
                    $sent_to = addslashes(htmlspecialchars($email->sent_to ?? ''));
                    $sent_from = addslashes(htmlspecialchars($email->sent_from ?? ''));
                    $title = addslashes(htmlspecialchars($email->title ?? ''));
                    $cc_email = addslashes(htmlspecialchars($email->cc_email ?? ''));
                    $subject = addslashes(htmlspecialchars($email->subject ?? ''));

                    // Escape template safely (replace newlines and quotes)
                    $template = str_replace(["\r", "\n", "'"], [' ', ' ', "\\'"], $email->template ?? '');
                    $template = htmlspecialchars($template, ENT_QUOTES); // prevents XSS

                    return '<div class="btn-group">
                        <button type="button" class="btn btn-sm btn-outline-primary"
                            onclick="showDetailsModal(
                                ' . $email->id . ',
                                \'' . $sent_to . '\',
                                \'' . $sent_from . '\',
                                \'' . $title . '\',
                                \'' . $cc_email . '\',
                                \'' . $subject . '\',
                                \'' . $template . '\'
                            )">
                            <i class=\"fas fa-eye\"></i> View
                        </button>
                    </div>';
                })

                ->rawColumns(['action', 'updated_at'])
                ->make(true);
        }
    }
    public function sendMessageToApplicant(Request $request)
    {
        try {
            $request->validate([
                'phone_number' => 'required',
                'message' => 'required',
            ]);

            $phone_numbers = explode(',', $request->input('phone_number'));
            $message = $request->input('message');
            $applicant_id = $request->input('applicant_id');

            foreach ($phone_numbers as $phone) {
                $applicant = Applicant::where('applicant_phone', 'like', '%' . $phone . '%')
                    ->orWhere('applicant_phone_secondary', 'like', '%' . $phone . '%')
                    ->orWhere('applicant_landline', 'like', '%' . $phone . '%')
                    ->first();

                if ($applicant) {
                    $is_saved = $this->saveSMSDB($phone, $message, 'Horsefly\Applicant', $applicant->id);
                } else {
                    $contact = Contact::where('contact_phone', $phone)->first();
                    if ($contact) {
                        $is_saved = $this->saveSMSDB($phone, $message, $contact->contactable_type, $contact->contactable_id);
                    } else {
                        $is_saved = $this->saveSMSDB($phone, $message, 'unknown', null);
                    }
                }

                if (!$is_saved) {
                    return response()->json([
                        'success' => false,
                        'message' => 'SMS saving failed.',
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'SMS sent and stored successfully.',
            ]);
        } catch (ValidationException $ve) {
            // Validation errors will be caught separately
            return response()->json([
                'error' => $ve->validator->errors()->first(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An unexpected error occurred: ' . $e->getMessage(),
            ], 500);
        }
    }

    // public function sendMessageToApplicant(Request $request)
    // {
    //     return $request->all();
    //     try {
    //         $phone_number = $request->input('phone_number');
    //         $message = $request->input('message');

    //         if (!$phone_number || !$message) {
    //             return response()->json([
    //                 'error' => 'Phone number and message are required.'
    //             ], 400);
    //         }

    //         // Encode message to be safely used in a URL
    //         $encoded_message = urlencode($message);

    //         $url = 'http://milkyway.tranzcript.com:1008/sendsms?username=admin&password=admin&phonenumber='
    //             . $phone_number . '&message=' . $encoded_message . '&port=1&report=JSON&timeout=0';

    //         $curl = curl_init();
    //         curl_setopt_array($curl, [
    //             CURLOPT_URL => $url,
    //             CURLOPT_RETURNTRANSFER => true,
    //             CURLOPT_HEADER => false,
    //             CURLOPT_TIMEOUT => 10,
    //         ]);

    //         $response = curl_exec($curl);
    //         $curlError = curl_error($curl);
    //         curl_close($curl);

    //         if ($response === false) {
    //             return response()->json([
    //                 'error' => 'Failed to connect to SMS API: ' . $curlError,
    //                 'query_string' => $url
    //             ], 500);
    //         }

    //         // Try to parse JSON response
    //         $parsed = json_decode($response, true);
    //         if (json_last_error() === JSON_ERROR_NONE) {
    //             $report = $parsed['result'] ?? null;
    //             $time = $parsed['time'] ?? null;
    //             $phone = $parsed['phonenumber'] ?? null;
    //         } else {
    //             // Fallback (non-JSON API response)
    //             $report = explode('"', strstr($response, "result"))[2] ?? null;
    //             $time = explode('"', strstr($response, "time"))[2] ?? null;
    //             $phone = explode('"', strstr($response, "phonenumber"))[2] ?? null;
    //         }

    //         if ($report === "success") {
    //             return response()->json([
    //                 'success' => 'SMS sent successfully!',
    //                 'data' => $response,
    //                 'phonenumber' => $phone,
    //                 'time' => $time,
    //                 'report' => $report
    //             ]);
    //         } elseif ($report === "sending") {
    //             return response()->json([
    //                 'success' => 'SMS is sending, please check later!',
    //                 'data' => $response,
    //                 'phonenumber' => $phone,
    //                 'time' => $time,
    //                 'report' => $report
    //             ]);
    //         } else {
    //             return response()->json([
    //                 'error' => 'SMS failed, please check your device or settings!',
    //                 'data' => $response,
    //                 'report' => $report,
    //                 'query_string' => $url
    //             ]);
    //         }

    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'error' => 'An unexpected error occurred: ' . $e->getMessage()
    //         ], 500);
    //     }
    // }
    public function sendRejectionEmail(Request $request)
    {
        try {


            return response()->json(['message' => 'Rejection email sent successfully.']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to send email: ' . $e->getMessage()], 500);
        }
    }
    public function saveEmailsForApplicants(Request $request)
    {
        try {
            $sale_id = $request->input('sale_id', null);

            $emailData = $request->input('app_email');
            $template = EmailTemplate::where('slug', 'send_job_vacancy_details')->where('is_active', 1)->first();

            if ($emailData != null && $template) {
                $dataEmail = explode(',', $emailData);

                $email_from = $template->from_email;
                $email_subject = $request->input('email_subject');
                $email_body = $request->input('email_body');
                $email_title = $template->title;

                foreach ($dataEmail as $email) {
                    $applicant = Applicant::where('applicant_email', $email)->orWhere('applicant_email_secondary', $email)->first();
                    $is_save = $this->saveEmailDB($email, $email_from, $email_subject, $email_body, $email_title, $applicant->id, $sale_id);
                    if (!$is_save) {
                        // Optional: throw or log
                        Log::warning('Email saved to DB failed for applicant ID: ' . $applicant->id);
                        throw new \Exception('Email is not stored in DB');
                    }
                }
            }
            return response()->json(['success' => true, 'message' => 'Email saved successfully']);
        } catch (\Exception $e) {
            return  response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        }
    }
    public function saveComposedEmail(Request $request)
    {
        try {
            $emailData = $request->input('app_email');
            $from_email = $request->input('from_email') ?? 'info@kingsburypersonnel.com';

            if ($emailData != null) {
                $dataEmail = explode(',', $emailData);

                $email_from = $from_email;
                $email_subject = $request->input('email_subject');
                $email_body = $request->input('email_body');
                $email_title = $request->input('email_subject');

                foreach ($dataEmail as $email) {
                    $applicant = Applicant::where('applicant_email', $email)->orWhere('applicant_email_secondary', $email)->first();
                    if ($applicant) {
                        $is_save = $this->saveEmailDB($email, $email_from, $email_subject, $email_body, $email_title, $applicant->id);
                    } else {
                        $is_save = $this->saveEmailDB($email, $email_from, $email_subject, $email_body, $email_title, null);
                    }
                    if (!$is_save) {
                        // Optional: throw or log
                        Log::warning('Email saved to DB failed');
                        throw new \Exception('Email is not stored in DB');
                    } else {
                        return response()->json(['success' => true, 'message' => 'Email saved successfully']);
                    }
                }
            }
        } catch (\Exception $e) {
            return  response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /*************************************** */
    public function messageReceive(Request $request)
    {
        try {
            $phoneNumber_gsm = $request->input('phoneNumber');
            $phoneNumber = preg_replace('/^(\+44|44)/', '0', $phoneNumber_gsm);
            $message = $request->input('message');
            $msg_id = substr(md5(time()), 0, 14);
            $date_time = $request->input('time');
            $date_time_arr = explode(" ", $date_time);
            $date_res = $date_time_arr[0];
            $date = str_replace("/", "-", $date_res);
            $time = $date_time_arr[1];

            $data = [];

            $lastMessage = Message::where('phone_number', $phoneNumber)
                ->where('status', 'outgoing')
                ->latest()
                ->first();

            $applicant = Applicant::where('applicant_phone', $phoneNumber)
                ->orWhere('applicant_landline', $phoneNumber)
                ->orWhere('applicant_phone_secondary', $phoneNumber)
                ->first();

            $contact = Contact::where('contact_phone', $phoneNumber)->first();

            if ($applicant) {
                $data['module_id'] = $applicant->id;
                $data['module_type'] = 'Horsefly\Applicant';
            } elseif ($contact) {
                $data['module_id'] = $contact->contactable_id;
                $data['module_type'] = $contact->contactable_type;
            } else {
                $data['module_type'] = 'unknown';
            }

            if ($data) {
                $applicant_msg = new Message();
                $applicant_msg->module_id = $data['module_id'];
                $applicant_msg->module_type = $data['module_type'];
                $applicant_msg->user_id = $lastMessage ? $lastMessage->user_id : null;
                $applicant_msg->msg_id = $msg_id;
                $applicant_msg->message = $message;
                $applicant_msg->phone_number = $phoneNumber;
                $applicant_msg->date = $date;
                $applicant_msg->time = $time;
                $applicant_msg->created_at = Carbon::parse($date . '' . $time)->toDateTimeString();
                $applicant_msg->updated_at = Carbon::parse($date . '' . $time)->toDateTimeString();
                $applicant_msg->status = 'incoming';
                $applicant_msg->save();

                return response()->json(['message' => 'Message received and saved successfully.']);
            } else {
                return response()->json(['message' => 'Phone number not found in Applicant'], 404);
            }
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to save message: ' . $e->getMessage()], 500);
        }
    }
    public function sendChatBoxMsg(Request $request)
    {
        $request->validate([
            'recipient_id' => 'required',
            'recipient_type' => 'required',
            'recipient_phone' => ['required_unless:recipient_type,user', 'nullable', 'string', 'max:50'],
            'message' => 'required|string',
        ]);

        if ($request->recipient_type == 'user') {
            $recipient_type = 'Horsefly\User';
        } else {
            $recipient_type = 'Horsefly\Applicant';
        }

        $message = new Message();
        $message->module_id = (int) $request->recipient_id;
        $message->module_type = $recipient_type;
        $message->user_id = Auth::id();
        $message->msg_id = 'D' . mt_rand(1000000000000, 9999999999999);
        $message->message = $request->message;
        $message->phone_number = $request->recipient_type == 'user' ? null : $request->recipient_phone;
        $message->date = now()->toDateString();
        $message->time = now()->toTimeString();
        $message->is_sent = 0;
        $message->is_read = 0;
        $message->status = 'outgoing';
        $message->save();

        return response()->json([
            'id' => $message->id,
            'msg_id' => $message->msg_id,
            'user_id' => $message->user_id,
            'module_type' => $message->user_id,
            'module_id' => $message->module_id,
            'user_name' => Auth::user()->name,
            'message' => $message->message,
            'phone_number' => $message->phone_number,
            'date' => $message->date,
            'time' => $message->time,
            'status' => $message->status,
            'is_sent' => $message->is_sent,
            'is_read' => $message->is_read,
            'is_sender' => true,
            'created_at' => date('d M Y, H:i A', strtotime($message->date . ' ' . $message->time)),
        ]);
    }
    public function getChatBoxMessages(Request $request)
    {
        try {
            // Validate request input
            $request->validate([
                'recipient_id'   => 'required',
                'recipient_type' => 'required',
            ]);

            // Retrieve necessary data from the request
            $recipientId   = $request->recipient_id;
            $recipientType = $request->recipient_type;
            $beforeId      = $request->before_id; // 👈 important

            // Determine the model type for recipient (either Applicant or User)
            $moduleType = ($recipientType === 'applicant' || $recipientType === 'unknown') ? Applicant::class : User::class;
            $list_ref = $request->input('list_ref', '');

            // Initialize recipient data (empty by default)
            $recipient = null;

            // Handle the 'unknown-chat' case differently
            if ($list_ref === 'unknown-chat') {
                Message::where('phone_number', 'like', '%' . $recipientId . '%')
                    ->where('module_type', $moduleType)
                    ->where('status', 'incoming')
                    ->update(['is_read' => 1]);  // Ensure it's an integer value (0 or 1)

                // Special handling for unknown-chat (i.e., by phone number)
                $messages = Message::where('phone_number', 'like', '%' . $recipientId . '%')
                    ->where('module_type', $moduleType)
                    ->orderByDesc('id')
                    ->limit(10) // chunk size
                    ->get(); // Fetch messages

                // Assign recipient data in the case of 'unknown-chat'
                $recipient = [
                    'id'              => $messages->isEmpty() ? null : $messages->first()->id,  // Get the ID of the first message or null if no messages
                    'applicant_name'  => 'Unknown Number',
                    'applicant_phone' => $recipientId,
                ];
            } elseif ($list_ref === 'user-chat') {
                // Fetch the recipient (the other agent)
                $recipient = User::findOrFail($recipientId);
                $currentUserId = Auth::id();

                // A thread between me and the recipient is the set of rows where
                // (module_id, user_id) is (recipient, me) or (me, recipient)
                $threadScope = function ($query) use ($recipientId, $currentUserId) {
                    $query->where('module_type', 'Horsefly\\User')
                        ->where(function ($q) use ($recipientId, $currentUserId) {
                            $q->where('module_id', $recipientId)->where('user_id', $currentUserId);
                        })
                        ->orWhere(function ($q) use ($recipientId, $currentUserId) {
                            $q->where('module_id', $currentUserId)->where('user_id', $recipientId);
                        });
                };

                // Mark messages sent to me by the recipient as read
                Message::where('module_id', $currentUserId)
                    ->where('user_id', $recipientId)
                    ->where('module_type', 'Horsefly\\User')
                    ->where('is_read', 0)
                    ->update(['is_read' => 1]);

                $query = Message::where($threadScope)
                    ->with('user')
                    ->orderByDesc('id')
                    ->limit(10);

                if ($beforeId) {
                    $query->where('id', '<', $beforeId);
                }

                $messages = $query->get();
            } else {
                // Fetch the recipient details when the list_ref is not 'unknown-chat'
                $recipient = $moduleType::findOrFail($recipientId);

                Message::where('module_id', $recipientId)
                    ->where('module_type', $moduleType)
                    ->where('status', 'incoming')
                    ->update(['is_read' => 1]);  // Ensure it's an integer value (0 or 1)


                // Base query for fetching messages
                $query = Message::where('module_id', $recipientId)
                    ->where('module_type', $moduleType)
                    ->with('user')
                    ->orderByDesc('id')
                    ->limit(10); // chunk size

                // Load older messages (before a specific message ID)
                if ($beforeId) {
                    $query->where('id', '<', $beforeId);
                }

                // Fetch the messages
                $messages = $query->get();
            }

            // Reverse the messages for UI (oldest → newest)
            $formattedMessages = $messages->reverse()->values()->map(function ($message) use ($list_ref) {
                $isSender = $message->user_id == Auth::id();

                return [
                    'id'           => $message->id,
                    'message'      => $message->message,
                    'created_at'   => $message->created_at->format('d M Y, h:i A'),
                    'is_sender'    => $isSender,
                    'user_name'    => $message->user?->name ?? 'Unknown',
                    'is_read'      => $message->is_read ?? 0,
                    'is_sent'      => $message->is_sent ?? 0,
                    'phone_number' => $message->phone_number,
                    'status'       => $list_ref === 'user-chat'
                        ? ($isSender ? 'Sent' : 'Received')
                        : ($message->status === 'outgoing' ? 'Sent' : 'Received'),
                ];
            });

            // Determine recipient's status (active or not)
            $recipientStatus = $messages->isEmpty() ? false : true;

            // Prepare the response
            if ($list_ref === 'user-chat') {
                $recipientData = [
                    'id'    => $recipient->id,
                    'name'  => $recipient->name ?? 'Unknown',
                    'phone_primary' => null,
                    'phone_secondary' => null,
                    'status' => $recipientStatus ? 'active' : 'inactive',
                ];
            } else {
                $recipientData = [
                    'id'    => $recipient['id'] ?? $recipient->id,
                    'name'  => $recipient['applicant_name'] ?? $recipient->applicant_name ?? 'Unknown',
                    'phone_primary' => $recipient['applicant_phone'] ?? $recipient->applicant_phone ?? '0',
                    'phone_secondary' => $recipient['applicant_phone_secondary'] ?? $recipient->applicant_phone_secondary ?? '0',
                    'status' => $recipientStatus ? 'active' : 'inactive',
                ];
            }

            return response()->json([
                'recipient' => $recipientData,
                'messages' => $formattedMessages,
                'has_more' => $messages->count() == 10,
            ]);
        } catch (\Exception $e) {
            // Log the error and return a detailed response
            Log::error("Error fetching chat messages: " . $e->getMessage());
            return response()->json(['error' => 'An error occurred while fetching chat messages. Please try again later. => ' . $e->getMessage()], 500);
        }
    }
    public function getApplicantsForMessage(Request $request)
    {
        $limit = (int) $request->input('limit', 10);
        $start = (int) $request->input('start', 0);

        $raw_query = Applicant::with([
            'messages' => function ($query) {
                $query->latest()->limit(1);
            }
        ])
            ->withMax('messages', 'created_at')          // <- adds messages_max_created_at
            ->withCount([
                'messages as messages_count',
                'messages as unread_count' => function ($query) {
                    $query->where('module_type', 'Horsefly\\Applicant')
                        ->where('status', 'incoming')
                        ->where('is_read', 0);
                }
            ]);


        if (!empty($request->search)) {
            $search = trim($request->search);
            $raw_query->where('applicant_name', 'like', '%' . $search . '%')
                ->orWhere('applicant_phone', 'like', '%' . $search . '%');
        }

        $applicants = $raw_query
            ->orderByDesc(DB::raw('unread_count > 0'))  // unread first
            ->orderByDesc('unread_count')
            ->orderByDesc('messages_max_created_at')     // latest message first
            ->orderByDesc('messages_count')
            ->orderBy('applicant_name')
            ->offset($start)
            ->limit($limit)
            ->get();



        $data = $applicants->map(function ($applicant) {

            $lastMessage = $applicant->messages->first();

            return [
                'id'   => $applicant->id,
                'name' => $applicant->applicant_name,
                'last_message' => $lastMessage ? [
                    'message'       => Str::limit($lastMessage->message, 50),
                    'time'          => $lastMessage->created_at
                        ? $lastMessage->created_at->format('h:i A')
                        : '',
                    'is_sent'       => (int) ($lastMessage->is_sent ?? 0),
                    'is_read'       => (int) ($lastMessage->is_read ?? 0),
                    'unread_count'  => (int) $applicant->unread_count,
                ] : null,
            ];
        });

        return response()->json([
            'data'     => $data,
            'has_more' => $data->count() == $limit // key logic
        ]);
    }
    public function getUnknownMessage(Request $request)
    {
        $limit = (int) $request->input('limit', 10);
        $start = (int) $request->input('start', 0);

        $query = Message::query()
            ->leftJoin('applicants', 'messages.module_id', '=', 'applicants.id')
            ->where('messages.module_type', 'Horsefly\\Applicant')
            ->where(function ($q) {
                $q->whereNull('messages.module_id')
                    ->orWhereNull('applicants.id');
            })
            ->select([
                'messages.phone_number',
                DB::raw('MAX(messages.created_at) as last_message_at'),
                DB::raw('COUNT(*) as messages_count'),
                DB::raw('SUM(
                    CASE 
                        WHEN messages.status = "incoming" 
                        AND messages.is_read = 0 
                        AND (messages.module_id IS NULL OR applicants.id IS NULL)
                        THEN 1 
                        ELSE 0 
                    END
                ) as unread_count')
            ])
            ->groupBy('messages.phone_number');

        if (!empty($request->search)) {
            $query->where('messages.phone_number', 'like', '%' . $request->search . '%');
        }

        $messages = $query
            ->orderByDesc('unread_count')       // unread first
            ->orderByDesc('messages_count')     // then by volume
            ->orderByDesc('last_message_at')    // then latest conversation
            ->offset($start)
            ->limit($limit)
            ->get();


        $data = $messages->map(function ($message) {
            $lastMessage = Message::where('phone_number', $message->phone_number)
                ->orderByDesc('created_at')
                ->first();

            return [
                'phone_number' => $message->phone_number,
                'messages_count' => $message->messages_count,
                'unread_count' => $message->unread_count, // take from aggregated query
                'name' => 'Unknown Number',
                'last_message' => $lastMessage ? [
                    'message' => Str::limit($lastMessage->message, 50),
                    'time' => $lastMessage->created_at
                        ? $lastMessage->created_at->format('h:i A')
                        : '',
                    'is_sent' => (int) ($lastMessage->is_sent ?? 0),
                    'is_read' => (int) ($lastMessage->is_read ?? 0),
                ] : null,
            ];
        });

        return response()->json([
            'data' => $data,
            'has_more' => $data->count() == $limit,
        ]);
    }
    public function getUserChats(Request $request)
    {
        try {
            $currentUserId = Auth::id();

            $limit = (int) $request->input('limit', 10);
            $start = (int) $request->input('start', 0);

            // Last message for each thread between the current user and another user
            // (a thread is the set of rows where (module_id, user_id) is (A,B) or (B,A))
            $lastMessageIds = DB::table('messages')
                ->select(DB::raw('MAX(id) as id'))
                ->where('module_type', 'Horsefly\\User')
                ->whereNull('deleted_at')
                ->where(function ($q) use ($currentUserId) {
                    $q->where('user_id', $currentUserId)
                        ->orWhere('module_id', $currentUserId);
                })
                ->groupBy(DB::raw('IF(user_id = ' . $currentUserId . ', module_id, user_id)'));

            $unreadCounts = DB::table('messages')
                ->select('user_id', DB::raw('COUNT(*) as unread_count'))
                ->where('module_type', 'Horsefly\\User')
                ->whereNull('deleted_at')
                ->where('module_id', $currentUserId)
                ->where('is_read', 0)
                ->groupBy('user_id');

            // Resolve the "other party" for each last-message row, then join back to users
            $threads = DB::table('messages as m')
                ->joinSub($lastMessageIds, 'last_ids', function ($join) {
                    $join->on('m.id', '=', 'last_ids.id');
                })
                ->select(
                    'm.message',
                    'm.created_at',
                    DB::raw('IF(m.user_id = ' . $currentUserId . ', m.module_id, m.user_id) as other_user_id')
                );

            $usersQuery = DB::table('users')
                ->where('users.id', '!=', $currentUserId)
                ->where('users.is_active', 1)
                ->whereNull('users.deleted_at')
                ->leftJoinSub($threads, 'threads', function ($join) {
                    $join->on('users.id', '=', 'threads.other_user_id');
                })
                ->leftJoinSub($unreadCounts, 'unread_msgs', function ($join) {
                    $join->on('users.id', '=', 'unread_msgs.user_id');
                })
                ->select(
                    'users.id',
                    'users.name',
                    'threads.message',
                    'threads.created_at',
                    DB::raw('COALESCE(unread_msgs.unread_count, 0) as unread_count')
                );

            if ($request->search) {
                $usersQuery->where('users.name', 'like', '%' . $request->search . '%');
            }

            $users = $usersQuery
                ->orderByDesc(DB::raw('unread_count > 0'))
                ->orderByDesc('unread_count')
                ->orderByDesc('threads.created_at')
                ->orderBy('users.name')
                ->offset($start)
                ->limit($limit)
                ->get();

            $data = $users->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'last_message' => $user->message ? [
                        'message' => Str::limit($user->message, 50),
                        'time' => $user->created_at ? Carbon::parse($user->created_at)->format('h:i A') : '',
                        'unread_count' => (int) $user->unread_count,
                    ] : null,
                ];
            });

            return response()->json([
                'data' => $data,
                'has_more' => $data->count() == $limit
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching user chats: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch user chats'], 500);
        }
    }

    public function deleteChatBoxMsg(Request $request)
    {
        if (!Auth::user() || !Auth::user()->is_admin) {
            return response()->json(['error' => 'Only an admin can delete messages.'], 403);
        }

        $request->validate([
            'message_id' => 'required',
        ]);

        $message = Message::find($request->message_id);

        if (!$message) {
            return response()->json(['error' => 'Message not found.'], 404);
        }

        $message->delete();

        return response()->json([
            'success' => true,
            'message_id' => $message->id,
        ]);
    }


}
