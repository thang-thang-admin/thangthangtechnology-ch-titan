<?php

namespace Chatify\Http\Controllers\Api;

use Illuminate\Support\Str;
use App\Models\Customer as User;
use Illuminate\Http\Request;
use App\Models\ChMessage as Message;
use App\Models\ChFavorite as Favorite;
use App\Models\ChMessage;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Response;
use Chatify\Facades\ChatifyMessenger as Chatify;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Google\Auth\Credentials\ServiceAccountCredentials;


class MessagesController extends Controller
{
    protected $perPage = 30;

    /**
     * Authinticate the connection for pusher
     *
     * @param Request $request
     * @return void
     */
    public function pusherAuth(Request $request)
    {
        // sanctum guard သို့မဟုတ် web guard တစ်ခုခုဖြင့် User ကို အသေအချာ ဆွဲထုတ်ရန်
        $user = $request->user('sanctum') ?? Auth::user();

        if ($user) {
            return Chatify::pusherAuth(
                $user,
                $user,
                $request['channel_name'],
                $request['socket_id']
            );
        }

        return response()->json(['message' => 'Not authenticated'], 403);
    }

    /**
     * Fetch data by id for (user/group)
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function idFetchData(Request $request)
    {
        // Safely resolve the authenticated user (sanctum guard first, then default guard)
        $user = $request->user('sanctum') ?? Auth::user();

        if (!$user) {
            return response()->json(['message' => 'Not authenticated'], 401);
        }

        // Favorite
        $favorite = Chatify::inFavorite($request['id']);

        // User data
        $fetch = User::where('id', $request['id'])->first();
        if ($fetch) {
            $fetch = Chatify::getUserWithAvatar($fetch);
            // The frontend expects `fetch.name` while the Customer model uses `customer_name`
            $fetch->name = $fetch->customer_name ?? $fetch->name ?? '';
            $userAvatar = $fetch->image;
        }

        // send the response
        return Response::json([
            'favorite' => $favorite,
            'fetch' => $fetch ?? null,
            'user_avatar' => $userAvatar ?? null,
        ]);
    }

    /**
     * This method to make a links for the attachments
     * to be downloadable.
     *
     * @param string $fileName
     * @return \Illuminate\Http\JsonResponse
     */
    public function download($fileName)
    {
        $path = config('chatify.attachments.folder') . '/' . $fileName;
        if (Chatify::storage()->exists($path)) {
            return response()->json([
                'file_name' => $fileName,
                'download_path' => Chatify::storage()->url($path)
            ], 200);
        } else {
            return response()->json([
                'message' => "Sorry, File does not exist in our server or may have been deleted!"
            ], 404);
        }
    }

    /**
     * Send a message to database
     *
     * @param Request $request
     * @return JSON response
     */
    public function send(Request $request)
    {
        // 1. User ကို Guard ဖြင့် သို့မဟုတ် request user ဖြင့် လုံခြုံစွာ ဆွဲထုတ်ရန်
        $user = $request->user('sanctum') ?? Auth::user();

        //user

        if (!$user) {
            return response()->json(['message' => 'Not authenticated'], 401);
        }

        $fromId = $user->id;

        // default variables
        $error = (object)[
            'status' => 0,
            'message' => null
        ];
        $attachment = null;
        $attachment_title = null;

        //if there is attachment [file]
        if ($request->hasFile('file')) {
            $allowed_images = Chatify::getAllowedImages();
            $allowed_files  = Chatify::getAllowedFiles();
            $allowed        = array_merge($allowed_images, $allowed_files);

            $file = $request->file('file');
            $extension = strtolower($file->extension());

            if (in_array($extension, $allowed)) {
                $attachment_title = $file->getClientOriginalName();
                $uniqueName = Str::uuid() . "." . $extension;

                $filePath = $file->storeAs(
                    'profile_files',
                    $uniqueName,
                    config('chatify.storage_disk_name')
                );

                $attachment = Storage::disk(config('chatify.storage_disk_name'))->url($filePath);
            } else {
                $error->status = 1;
                $error->message = "File extension not allowed!";
            }
        }

        if (!$error->status) {
            // send to database
            $message = Chatify::newMessage([
                'type' => $request['type'],
                'from_id' => $fromId, // <-- $fromId ကို သုံးပါ
                'to_id' => $request['id'],
                'body' => htmlentities(trim($request['message']), ENT_QUOTES, 'UTF-8'),
                'sent_by' => $request['type'] == 'shipper' ? 'shipper_admin' : 'shipper_admin',
                'attachment' => ($attachment) ? json_encode((object)[
                    'new_name' => $attachment,
                    'old_name' => htmlentities(trim($attachment_title), ENT_QUOTES, 'UTF-8'),
                ]) : null,
            ]);

            // fetch message to send it with the response
            $messageData = Chatify::parseMessage($message);

            // send to user using pusher
            Chatify::push("private-chatify." . $request['id'], 'messaging', [
                'from_id' => $fromId, // <-- ဤနေရာတွင်လည်း Auth::user()->id အစား $fromId ကို သုံးပါ
                'to_id' => $request['id'],
                'message' => Chatify::messageCard($messageData, true)
            ]);
        }

        // send the response
        return Response::json([
            'status' => '200',
            'error' => $error,
            'message' => $messageData ?? [],
            'tempID' => $request['temporaryMsgId'] ?? null,
        ]);
    }

    public function sendMessage(Request $request)
    {
        // default variables
        $error = (object)[
            'status' => 0,
            'message' => null
        ];
        $attachment = null;
        $attachment_title = null;

        $user = $request->user('sanctum') ?? Auth::user();


        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $fromId = $user->id;

        //if there is attachment [file]
        if ($request->hasFile('file')) {
            // allowed extensions
            $allowed_images = Chatify::getAllowedImages();
            $allowed_files  = Chatify::getAllowedFiles();
            $allowed        = array_merge($allowed_images, $allowed_files);

            $file = $request->file('file');
            $extension = strtolower($file->extension());

            if (in_array($extension, $allowed)) {
                // get attachment name
                $attachment_title = $file->getClientOriginalName();

                // generate unique file name
                $uniqueName = Str::uuid() . "." . $extension;

                // upload file to S3 disk
                $filePath = $file->storeAs(
                    'profile_files',  // e.g. 'attachments'
                    $uniqueName,
                    config('chatify.storage_disk_name')    // e.g. 's3'
                );

                // get full URL for the stored file on S3
                $attachment = Storage::disk(config('chatify.storage_disk_name'))->url($filePath);

            } else {
                $error->status = 1;
                $error->message = "File extension not allowed!";
            }
        }

        if (!$error->status) {
            // send to database
            $message = Chatify::newMessage([
                'type' => $request['type'],
                'from_id' => $fromId,
                'to_id' => $request['to_id'],
                'body' => htmlentities(trim($request['message']), ENT_QUOTES, 'UTF-8'),
                'sent_by' => $request['type'] == 'shipper' ? 'shipper_delivery' : 'delivery_shipper',
                'attachment' => ($attachment) ? json_encode((object)[
                    'new_name' => $attachment,
                    'old_name' => htmlentities(trim($attachment_title), ENT_QUOTES, 'UTF-8'),
                ]) : null,
            ]);

            // fetch message to send it with the response
            $messageData = Chatify::parseMessage($message);

            // send to user using pusher
            // if (Auth::user()->id != $request['id']) {
            // send to user using pusher
            Chatify::push("private-chatify." . $request['to_id'], 'messaging', [
                'from_id' => $fromId, // <-- Auth::user()->id အစား အပေါ်မှာ ယူထားတဲ့ $fromId ကို သုံးပါ
                'to_id' => $request['to_id'],
                'message' => Chatify::messageCard($messageData, true)
            ]);
            
            // }
        }

        // send the response
        return Response::json([
            'status' => '200',
            'error' => $error,
            'message' => $messageData ?? [],
            'tempID' => $request['temporaryMsgId'],
        ]);
    }

    private function getAttachmentLink($attachment)
    {
        if (!$attachment) {
            return [null, null, null]; // file, title, type
        }

        // If it's JSON (Chatify saves like this)
        if (Str::startsWith($attachment, '{')) {
            $data = json_decode($attachment, true);

            $file  = $data['new_name'] ?? null;
            $title = $data['old_name'] ?? null;

            // Guess type from extension
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $audioExtensions = ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'opus'];

            if (in_array($extension, $imageExtensions)) {
                $type = 'image';
            } elseif (in_array($extension, $audioExtensions)) {
                $type = 'audio';
            } else {
                $type = 'file';
            }

            return [$file, $title, $type];
        }

        // If it's just a direct link string
        $extension = strtolower(pathinfo($attachment, PATHINFO_EXTENSION));
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $audioExtensions = ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'opus'];

        if (in_array($extension, $imageExtensions)) {
            $type = 'image';
        } elseif (in_array($extension, $audioExtensions)) {
            $type = 'audio';
        } else {
            $type = 'file';
        }

        return [$attachment, basename($attachment), $type];
    }


    public function getMessages(Request $request, $toId)
    {
        // Safely resolve the authenticated user (sanctum guard first, then default guard)
        $user = $request->user('sanctum') ?? Auth::user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
                'data' => null,
            ], 401);
        }

        try {
            User::findOrFail($toId);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Recipient not found',
                'data' => null,
            ], 200);
        }

        $userId = $user->id;

        // ✅ Only fetch messages (NO block filter)
        $messages = ChMessage::where(function ($q) use ($userId, $toId) {
                $q->where('from_id', $userId)->where('to_id', $toId);
            })
            ->orWhere(function ($q) use ($userId, $toId) {
                $q->where('from_id', $toId)->where('to_id', $userId);
            })
            ->orderBy('created_at', 'asc')
            ->get();

        $formatted = $messages->map(function ($message) {
            [$attachment, $title, $attachmentType] = $this->getAttachmentLink($message->attachment);

            return [
                'id' => $message->id,
                'type' => $message->type,
                'from_id' => $message->from_id,
                'to_id' => $message->to_id,
                'body' => $message->body,
                'sent_by' => $message->sent_by,
                'attachment' => $attachment,
                'seen' => $message->seen ?? 0,
                'created_at' => $message->created_at->toJSON(),
                'updated_at' => $message->updated_at->toJSON(),
                'attachment_type' => $attachmentType,
            ];
        });

        return response()->json([
            'total' => $formatted->count(),
            'last_page' => 1,
            'last_message_id' => optional($formatted->last())['id'] ?? null,
            'messages' => $formatted,
        ]);
    }

    //user to user end

    /**
     * fetch [user/group] messages from database
     *
     * @param Request $request
     * @return JSON response
     */
    public function fetch(Request $request)
    {
        // Safely resolve the authenticated user (sanctum guard first, then default guard)
        $user = $request->user('sanctum') ?? Auth::user();

        if (!$user) {
            return response()->json(['message' => 'Not authenticated'], 401);
        }

        $query = Chatify::fetchMessagesQuery($request['id'], $user->id)->latest();
        $messages = $query->paginate($request->per_page ?? $this->perPage);

        $decodedMessages = $messages->map(function ($message) {
            if ($message->attachment) {
                $message->attachment = json_decode($message->attachment, true)['new_name'];
                $fileExtension = strtolower(pathinfo($message->attachment, PATHINFO_EXTENSION));
                if (in_array(strtolower($fileExtension), ['wav', 'mp3','m4a', 'ogg', 'flac','aac','opus','wma','aiff'])) {
                    $attachment_type = 'audio';
                } elseif (in_array($fileExtension, ['jpg', 'jpeg', 'png', 'gif'])) {
                    $attachment_type = 'image';
                } else {
                    $attachment_type = 'file';
                }
                $message->attachment_type = $attachment_type;
            } else {
                $message->attachment_type = null;
            }
            return $message;
        });

        // Safe check for last message and last message ID
        $lastMessage = collect($messages->items())->last();
        $lastMessageId = $lastMessage ? $lastMessage->id : null;

        $totalMessages = $messages->total();
        $lastPage = $messages->lastPage();

        $response = [
            'total' => $totalMessages,
            'last_page' => $lastPage,
            'last_message_id' => $lastMessageId,
            'messages' => $decodedMessages,
        ];

        return Response::json($response);
    }


    /**
     * Make messages as seen
     *
     * @param Request $request
     * @return void
     */
    public function seen(Request $request)
    {
        // Safely resolve the authenticated user (sanctum guard first, then default guard)
        $user = $request->user('sanctum') ?? Auth::user();

        if (!$user) {
            return response()->json(['message' => 'Not authenticated'], 401);
        }

        // make as seen
        $seen = Chatify::makeSeen($request['id']);
        // send the response
        return Response::json([
            'status' => $seen,
        ], 200);
    }

    /**
     * Get contacts list
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse response
     */
    public function getContacts(Request $request)
    {
        // Safely resolve the authenticated user (sanctum guard first, then default guard)
        $user = $request->user('sanctum') ?? Auth::user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $fromId = $user->id;

        // get all users that received/sent message from/to [Auth user]
        $users = Message::join('customers',  function ($join) {
            $join->on('ch_messages.from_id', '=', 'customers.id')
                ->orOn('ch_messages.to_id', '=', 'customers.id');
        })
            ->where(function ($q) use ($fromId) {
                $q->where('ch_messages.from_id', $fromId)
                    ->orWhere('ch_messages.to_id', $fromId);
            })
            ->where('customers.id', '!=', $fromId)
            ->select('customers.*', DB::raw('MAX(ch_messages.created_at) max_created_at'))
            ->orderBy('max_created_at', 'desc')
            ->groupBy('customers.id')
            ->paginate($request->per_page ?? $this->perPage);

        return response()->json([
            'contacts' => $users->items(),
            'total' => $users->total() ?? 0,
            'last_page' => $users->lastPage() ?? 1,
        ], 200);
    }

    /**
     * Put a user in the favorites list
     *
     * @param Request $request
     * @return void
     */
    public function favorite(Request $request)
    {
        // Safely resolve the authenticated user (sanctum guard first, then default guard)
        $user = $request->user('sanctum') ?? Auth::user();

        if (!$user) {
            return response()->json(['message' => 'Not authenticated'], 401);
        }

        $userId = $request['user_id'];
        // check action [star/unstar]
        $favoriteStatus = Chatify::inFavorite($userId) ? 0 : 1;
        Chatify::makeInFavorite($userId, $favoriteStatus);

        // send the response
        return Response::json([
            'status' => @$favoriteStatus,
        ], 200);
    }

    /**
     * Get favorites list
     *
     * @param Request $request
     * @return void
     */
    public function getFavorites(Request $request)
    {
        // Safely resolve the authenticated user (sanctum guard first, then default guard)
        $user = $request->user('sanctum') ?? Auth::user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $favorites = Favorite::where('user_id', $user->id)->get();
        foreach ($favorites as $favorite) {
            $favorite->user = User::where('id', $favorite->favorite_id)->first();
        }
        return Response::json([
            'total' => count($favorites),
            'favorites' => $favorites ?? [],
        ], 200);
    }

    /**
     * Search in messenger
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function search(Request $request)
    {
        // Safely resolve the authenticated user (sanctum guard first, then default guard)
        $user = $request->user('sanctum') ?? Auth::user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $input = trim(filter_var($request['input']));
        $records = User::where('id', '!=', $user->id)
            ->where('customer_name', 'LIKE', "%{$input}%")
            ->paginate($request->per_page ?? $this->perPage);
        foreach ($records->items() as $index => $record) {
            $records[$index] += Chatify::getUserWithAvatar($record);
        }

        return Response::json([
            'records' => $records->items(),
            'total' => $records->total(),
            'last_page' => $records->lastPage()
        ], 200);
    }

    /**
     * Get shared photos
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    // public function sharedPhotos(Request $request)
    // {
    //     $images = Chatify::getSharedPhotos($request['user_id']);

    //     foreach ($images as $key=>$image) {
    //         $images[$key] = asset('image/public/'.config('chatify.attachments.folder') . '/' . $image);
    //     }
    //     // send the response
    //     return Response::json([
    //         'shared' => $images ?? [],
    //     ], 200);
    // }

    public function sharedPhotos(Request $request)
    {
        // Safely resolve the authenticated user (sanctum guard first, then default guard)
        $user = $request->user('sanctum') ?? Auth::user();

        if (!$user) {
            return response()->json(['message' => 'Not authenticated'], 401);
        }

        $images = Chatify::getSharedPhotos($request['user_id']);

        foreach ($images as $key => $image) {
            // getAttachmentUrl now returns full URLs as-is (no double-encoding)
            $images[$key] = Chatify::getAttachmentUrl($image);
        }

        return Response::json([
            'shared' => $images ?? [],
        ], 200);
    }


    /**
     * Delete conversation
     *
     * @param Request $request
     * @return void
     */
    public function deleteConversation(Request $request)
    {
        // Safely resolve the authenticated user (sanctum guard first, then default guard)
        $user = $request->user('sanctum') ?? Auth::user();

        if (!$user) {
            return response()->json(['message' => 'Not authenticated'], 401);
        }

        // delete
        $delete = Chatify::deleteConversation($request['id']);

        // send the response
        return Response::json([
            'deleted' => $delete ? 1 : 0,
        ], 200);
    }

    public function updateSettings(Request $request)
    {
        // Safely resolve the authenticated user (sanctum guard first, then default guard)
        $user = $request->user('sanctum') ?? Auth::user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $fromId = $user->id;

        $msg = null;
        $error = $success = 0;

        // dark mode
        if ($request['dark_mode']) {
            $request['dark_mode'] == "dark"
                ? User::where('id', $fromId)->update(['dark_mode' => 1])  // Make Dark
                : User::where('id', $fromId)->update(['dark_mode' => 0]); // Make Light
        }

        // If messenger color selected
        if ($request['messengerColor']) {
            $messenger_color = trim(filter_var($request['messengerColor']));
            // Customer table uses `message_color` column
            User::where('id', $fromId)
                ->update(['message_color' => $messenger_color]);
        }
        // if there is a [file]
        if ($request->hasFile('avatar')) {
            // allowed extensions
            $allowed_images = Chatify::getAllowedImages();

            $file = $request->file('avatar');
            // check file size
            if ($file->getSize() < Chatify::getMaxUploadSize()) {
                if (in_array(strtolower($file->extension()), $allowed_images)) {
                    // delete the older one
                    if ($user->avatar != config('chatify.user_avatar.default')) {
                        $path = Chatify::getUserAvatarUrl($user->avatar);
                        if (Chatify::storage()->exists($path)) {
                            Chatify::storage()->delete($path);
                        }
                    }
                    // upload
                    $avatar = Str::uuid() . "." . $file->extension();
                    $update = User::where('id', $fromId)->update(['avatar' => $avatar]);
                    $file->storeAs(config('chatify.user_avatar.folder'), $avatar, config('chatify.storage_disk_name'));
                    $success = $update ? 1 : 0;
                } else {
                    $msg = "File extension not allowed!";
                    $error = 1;
                }
            } else {
                $msg = "File size you are trying to upload is too large!";
                $error = 1;
            }
        }

        // send the response
        return Response::json([
            'status' => $success ? 1 : 0,
            'error' => $error ? 1 : 0,
            'message' => $error ? $msg : 0,
        ], 200);
    }

    /**
     * Set user's active status
     *
     * @param Request $request
     * @return void
     */
    public function setActiveStatus(Request $request)
    {
        // Safely resolve the authenticated user (sanctum guard first, then default guard)
        $user = $request->user('sanctum') ?? Auth::user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $activeStatus = $request['status'] > 0 ? 1 : 0;
        $status = User::where('id', $user->id)->update(['active_status' => $activeStatus]);
        return Response::json([
            'status' => $status,
        ], 200);
    }
}
