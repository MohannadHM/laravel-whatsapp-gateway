<?php

// app/Services/formatingService.php

namespace App\Services;

// Default
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

// Packages
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Carbon\Carbon;
use DateTime;
use DateTimeZone;
use Ramsey\Uuid\Uuid;

class format
{
    // Create Hands on json to BOT Json
    // Require Request to modify the json
    // Returns Json Body
    public static function handsOn(Request $request) {
        // Create Hands on Json
        $json = $data = [
            "messageId"=> $request['coordinate']['messageId'],
            "channel"=> 'whatsapp',
            "from"=> '+' . ltrim($request['author']['id'], '+'),
            "to"=> '+' . ltrim(config('botinfo.whatsappPhoneNumber'), '+'),
            "content"=> [
                "contentType"=> "text",
                "text"=> 'HANDS ON', // Static message for hands on requests
            ],
            "event"=> "MoMessage",
            "whatsapp"=> [
                "senderName"=> $request['author']['fullName']
            ],
            "recievedAt"=> substr(Carbon::createFromTimestampUTC($request['lastUpdatedTS'])
                ->toIso8601String(), 0, 19) . ".000Z", // "2025-04-13T06:02:49.532Z",
            "timestamp"=> substr(Carbon::createFromTimestampUTC($request['lastUpdatedTS'])
                ->toIso8601String(), 0, 19) . ".000Z", // "2025-04-13T06:02:49.532Z",
        ];

        return $json;
    }

    // Create external Hands off json for external
    // Require Request & Channel to modify the json depending on the channel
    // Returns Json Body
    public static function handsOff(Request $request, $channel) {

        $messageId = self::extractMessageId($request['to']);
        // Create Hands off Json
        $json = [
            "coordinate" => [
                "companyKey"=> config('botinfo.companyKey'),
                "networkKey" => $channel,
                "externalId"=> $channel === "whatsapp" ? config('botinfo.whatsappExternalId') : config('botinfo.webSdkExternalId'),
                "botId"=> config('botinfo.botId'),
                "scope"=> config('botinfo.scope'),
                "messageId" => $messageId,
                "normalizedAuthorId" => ltrim($request['to'], '+')
            ],
            "type" => "control",
            "author" => [
                "id" => ltrim($request['to'], '+'),
                "fullName" => ""
            ],
            "payload" => null,
            "owner" => [
                "type" => "AGENT",
                "appId" => config('botinfo.botId')
            ]
        ];

        return $json;
    }

    // Create external Workqueue change json
    // Require Request & Channel & Type to modify the json depending on the channel and select the queue depending on type
    // Returns Json Body
    public static function changeWorkqueue(Request $request, $channel, $type) {

        // Create Workqueue Change Json
        $json = [
            "coordinate"=> isset($request['coordinate']) ? $request['coordinate'] : [
                "companyKey"=> config('botinfo.companyKey'),
                "networkKey"=> $channel,
                "externalId"=> $channel === "whatsapp" ? config('botinfo.whatsappExternalId') : config('botinfo.webSdkExternalId'),
                "botId"=> config('botinfo.botId'),
                "scope"=> config('botinfo.scope')
            ],
            "type"=> "workqueue",
            "author"=> [
                "id"=> isset($request['to']) ? ltrim($request['to'], '+') : ltrim($request['author']['id'], '+'),
                "fullName" => isset($request['author']) ? $request['author']['fullName'] : ""
            ],
            "newWorkQueue" => $type === 'AGENT' ? config('botinfo.AgentbotWorkqueue') : config('botinfo.chatbotWorkqueue')
        ];
        return $json;
    }

    // Convert external to Whatsapp bot
    // Require Request to modify the json
    // Returns Json Body
    public static function bot(Request $request) {

        // Create bot Json
        $json = [
            "messageId"=> $request['coordinate']['messageId'],
            "channel"=> 'whatsapp',
            "from"=> ltrim($request['author']['id'], '+'),
            "to"=> ltrim(config('botinfo.whatsappPhoneNumber'), '+'),
            "event"=> "MoMessage",
            "whatsapp"=> [
                "senderName"=> $request['author']['fullName']
            ],
            "recievedAt"=> Carbon::createFromTimestampUTC((int)($request['receivedTS']/1000))
                ->format('Y-m-d\TH:i:s.v\Z'), // Will output like "2025-04-13T06:02:49.000Z"
            "timestamp"=> Carbon::createFromTimestampUTC((int)($request['publishedTS']/1000))
                ->format('Y-m-d\TH:i:s.v\Z'), // Will output like "2025-04-13T06:02:49.000Z"
        ];

        if(isset($request['media'])) {
            $json['content'] = [
                "contentType" => $request['media'][0]['mediaType'] === "LOCATION" ? "location" : "media"
            ];

            // if media is location
            if($json['content']['contentType'] === "location") {
                // Match pattern like 24.822 46.641 from https://www.google.com/maps/place/24.822%2046.641/?entry=im
                if (preg_match('/\/place\/([\-0-9.]+)\s+([\-0-9.]+)/', urldecode($request['media'][0]['url']), $matches)) {
                    $json['content']['location'] = [
                        "latitude" => (float) $matches[1],
                        "longitude" => (float) $matches[2]
                    ];
                }
            }

            // if media is (Image, video, audio, document)
            if($json['content']['contentType'] === "media") {
                // Generate a unique Id
                $mediaId = Uuid::uuid4()->toString();

                $json['content']['media'] = [
                    "type" => strtolower($request['media'][0]['mediaType']),
                    "url" => $request['media'][0]['url'],
                    "mediaId" => $mediaId
                ];
            }
        }

        if(isset($request['text'])) {
            if(isset($json['content']['media'])) {
                $json['content']['media']['caption'] = $request['text'];
            } else {
                $json['content']['contentType'] = "text";
                $json['content']['text'] = $request['text'];
            }
        }

        return $json;

    }

    // Convert bot Whatsapp to meta Whatsapp
    // Require Request & Channel to modify the json depending on channel
    // Returns Json Body
    public static function sendMessage(Request $request, $channel) {

        $messageId = self::extractMessageId($request['to']);
        $text = self::extractText($request, $channel);
        $buttons = self::extractButtons($request, $channel);
        $media = self::extractMedia($request);

        // Create meta Json and Coordinates depending on Channel
        $json = [
            "coordinate" => [
                "companyKey"=> config('botinfo.companyKey'),
                "networkKey"=> $channel,
                "externalId"=> $channel === "whatsapp" ? config('botinfo.whatsappExternalId') : config('botinfo.webSdkExternalId'),
                "botId"=> config('botinfo.botId'),
                "scope"=> config('botinfo.scope'),
                "messageId" => $messageId,
                "normalizedAuthorId" => ltrim($request['to'], '+')
            ],
            "type" => "message",
            "author" => [
                "id" => ltrim($request['to'], '+'),
                "fullName" => ""
            ],
            "text" => $text ? $text : "",
        ];
        
        // Check for buttons and set the payload based on the channel
        if($channel === "whatsapp" && $buttons) {
            if(!empty($buttons)) {
                if(count($buttons) <= 3) {
                    $payload = [
                        "messaging_product" => "whatsapp",
                        "recipient_type" => "individual",
                        "to" => '+' . ltrim($request['to'], '+'),
                        "type" => "interactive",
                        "interactive" => [
                            "type" => "button",
                            "body" => [
                                "text" => $text
                            ],
                            "action" => [
                                "buttons" => $buttons
                            ]
                        ]
                    ];
                    // If there's media and buttons Set the image as the header
                    if($media) {
                        $payload['interactive']['header'] = [
                            "type" => "image",
                            "image" => [
                                "link" => $request['whatsapp']['media']['url']
                            ]
                        ];
                    }
                    $json['payload'] = json_encode($payload, JSON_UNESCAPED_SLASHES);
                } else if(count($buttons) <= 10) {
                    $json['payload'] = json_encode([
                        "messaging_product" => "whatsapp",
                        "recipient_type" => "individual",
                        "to" => '+' . ltrim($request['to'], '+'),
                        "type" => "interactive",
                        "interactive" => [
                            "type" => "list",
                            "header" => [
                                "type" => "text",
                                "text" => preg_match('/\*(.*?)\*/', $text, $m) ? $m[1] : ""
                            ],
                            "body" => [
                                "text" => preg_match('/\*(.*?)\*/', $text, $m) ? preg_replace('/^\*.*?\*\n?/', '', $text) : $text
                            ],
                            "action" => [
                                "button" => preg_match('/\p{Arabic}/u', $text) ? "الخيارات" : "Options",
                                "sections" => [
                                    [
                                        "title" => " ",
                                        "rows" => $buttons
                                    ]
                                ]
                            ]
                        ]
                    ], JSON_UNESCAPED_SLASHES);
                }
            }
        } else {
            if(!empty($buttons)) {
                // If there's media and buttons Set the image as the header
                if($media) {
                    $cardButtons = [];
                    foreach($buttons as $button) {
                        $cardButtons[] = [
                            "name" => $button['title'],
                            "type" => "SUGGESTED_REPLY",
                            "payload" => $button['title']
                        ];
                    } 
                    $json['genericCards'] = [
                        [
                            "title" => preg_match('/\*(.*?)\*/', $text, $m) ? $m[1] : "",
                            "subtitle" => preg_match('/\*(.*?)\*/', $text, $m) ? preg_replace('/^\*.*?\*\n?/', '', $text) : $text,
                            "headerImageUrl" => $request['whatsapp']['media']['url'],
                            "headerOverlayText" => "",
                            "description" => "",
                            "titleExt" => "",
                            "buttons" => $cardButtons
                        ]
                    ];
                } else {
                    $json['suggestedReplies'] = $buttons;
                }
            }
        }

        // Check for Media (Same payload for whatsapp and Web)
        if($media && empty($buttons)) {
            $json['media'] = [
                $media
            ];
        }

        return $json;
    }

    // Extract Message ID from the log file
    // Require User Id to fetch the last message Id from Logs
    // Returns last Message Id or empty string
    public static function extractMessageId($userId) {
        $messageId = "";
        // Find Log file
        $logPath = storage_path('logs/messageIds');
        $searchId = substr($userId, 1);
        if(file_exists($logPath)) {

            // Split the file into lines and find the last occurrence of the userId
            $file = new \SplFileObject($logPath, 'r');
            $file->seek(PHP_INT_MAX);
            $lastLine = $file->key();

            $lines = [];
            $target = max(0, $lastLine - 100 + 1);

            for ($i = $target; $i <= $lastLine; $i++) {
                $file->seek($i);
                $lines[] = $file->current();
            }

            // $lines = file($logPath);
            $lines = array_map('trim', $lines);
            $matchingLines = array_filter($lines, function ($line) use ($searchId) {
                return strpos($line, $searchId) !== false;
            });

            if(!empty($matchingLines)) {
                $lastLine = end($matchingLines);
                if ($lastLine) {
                    $pattern = '/wamid\.([^\"]+)"/'; // Adjust the regex pattern to capture the ID after the colon
                    if(preg_match($pattern, $lastLine, $matches)) {
                        $messageId = "wamid." . $matches[1]; // Check if any ID is captured
                    }
                    
                    // Remove the line from the file
                    $allLines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                    $filteredLines = array_filter($allLines, function ($line) use ($searchId) {
                        return strpos($line, $searchId) === false;
                    });
                    
                    // Write the filtered lines back to the file
                    file_put_contents($logPath, implode(PHP_EOL, $filteredLines) . PHP_EOL);
                }
            }
        }
        return $messageId;
    }

    // Extract text from Tyntic Requests
    // Require Request to extract the text from it
    // Returns text or empty string
    public static function extractText(Request $request) {
        $message = isset($request->whatsapp["text"])? str_replace("\xE2\x80\x8E", '', $request->whatsapp["text"]): (isset($request->whatsapp["media"]['caption']) ? $request->whatsapp["media"]['caption'] : "");
        if($message) {
            if(preg_match('/1️⃣/', $message)) {
                $pos = strpos($message, "1️⃣");
                $message = $pos !== false? substr($message, 0, $pos) : $message;
            }
        }
        return $message;
    }

    // Extract Buttons from Tyntic Requests
    // Require Request to extract the buttons from it
    // Returns text or empty array
    public static function extractButtons(Request $request, $channel) {
        $message = isset($request->whatsapp["text"])? str_replace("\xE2\x80\x8E", '', $request->whatsapp["text"]): (isset($request->whatsapp["media"]['caption']) ? $request->whatsapp["media"]['caption'] : "");
        $buttons = [];
        $buttonsList = [];
        $i = 1;
        if($message) {
            if(preg_match('/1️⃣/', $message)) {
                $pos = strpos($message, "1️⃣");
                $text = $pos !== false? substr($message, 0, $pos): $message;
                $message = ($pos !== false) ? substr($message, $pos) : '';
                $buttons = explode("\n", $message);
                if(count($buttons) <= 3) {
                    foreach($buttons as $button) {
                        $button = str_replace(['1️⃣','2️⃣','3️⃣','4️⃣','5️⃣','6️⃣','7️⃣','8️⃣','9️⃣','0️⃣','🔟'], "", $button);
                        $button = ltrim($button);
                        if($channel === "whatsapp") {
                            $buttonsList[] = [
                                "type" => "reply",
                                "reply" => [
                                    "id" => $i,
                                    "title" => mb_substr($button, 0, 20)
                                ]
                            ];
                        } else {
                            $buttonsList[] = [
                                "title" => $button,
                                "message" => $button
                            ];
                        }
                        $i++;
                    }
                } else if(count($buttons) <= 10) {
                    foreach($buttons as $button) {
                        $button = str_replace(['1️⃣','2️⃣','3️⃣','4️⃣','5️⃣','6️⃣','7️⃣','8️⃣','9️⃣','0️⃣','🔟'], "", $button);
                        $button = ltrim($button);
                        if($channel === "whatsapp") {
                            $buttonsList[] = [
                                "id" => $i,
                                "title" => mb_strlen($button) > 24 ? mb_substr($button, 0, 21) . "..." : $button,
                                "description" => mb_strlen($button) > 24 ? (mb_strlen($button) > 72 ? mb_substr($button, 0, 69) . "..." : $button) : ""
                            ];
                        } else {
                            $buttonsList[] = [
                                "title" => $button,
                                "message" => $button
                            ];
                        }
                        $i++;
                    }
                }
            }
        }
        return $buttonsList;
    }

    // Extract Media from Tyntic Requests
    // Require Request to extract the media from it
    // Returns text or null
    public static function extractMedia(Request $request) {
        $media = null;
        if(isset($request->whatsapp["media"])) {
            $media = [
                "url" => $request['whatsapp']['media']['url'],
                "mediaType" => "IMAGE"
            ];
        }
        return $media;
    }
}