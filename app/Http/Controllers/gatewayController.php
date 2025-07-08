<?php

namespace App\Http\Controllers;

// Default
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

// Packages
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Carbon\Carbon;

// Services
use App\Services\format;
use App\Services\externalFunctions;

class gatewayController extends Controller
{
    // Receive requests
    public function webhook(Request $request) {

        // Check conversation Owner
        if((isset($request['owner']) && $request['owner']['type'] !== 'BOT') || (isset($request['operation']) && $request['operation'] === "AGENT_RESPONSE")) {
            // Log Received Requests
            $this->log($request->all(), 'agentConversations.log');
            return response()->json(['error' => 'BOT don\'t have permission over this conversation'], 200);
        }

        // Log Received Requests
        $this->log($request->all(), 'webhook.log');

        try {
            // Check request type (User Message, Hands On, Status Update)
            // Update message
            if($request['type'] === 'update') {
                
                // Return 200 response to avoid health check failures
                return response()->json(['status' => 'success'], 200);
            // Control message
            } else if($request['type'] === 'control') {
                
                // Check control type
                if($request['owner']['type'] === 'BOT') {
                    format::handsOn($request);
                }
            // User message
            } else if($request['type'] === 'message') {

                // If user is whatsapp user log Message Ids
                $isPhoneNumber = preg_match('/^966\d{9}$/', $request['author']['id']);
                if($request['coordinate']['externalId'] == config('botinfo.whatsappExternalId')) {
                    $endLog = new Logger("messages");
                    $endLog->pushHandler(new StreamHandler(storage_path("logs/messageIds")), Logger::INFO);
                    $endLogMsg = [$request['author']['id'] . ":" . $request['coordinate']['messageId']];
                    $endLog->info('',$endLogMsg);
                }

                // Convert to Chatbot Tyntic Request
                $json = format::Chatbot($request);

                // Send request to Chatbot through Apigee
                $token = env("Token");
                $response = Http::withHeaders(['client-token' => $token, 'Content-Type' => 'application/json;charset=UTF-8'])->post(config('botinfo.webhookApi'), $json);

                // if response status is not 200, log the error and return a 200 response to avoid health check failures
                if($response->status() !== 200) {
                    $msg = [
                        'error' => 'Failed to send request to Chatbot',
                        'status' => $response->status(),
                        'response' => $response->body(),
                        'request' => $request->all()
                    ];
                    $this->log($msg, 'webhookErrors.log');
                }
                return response()->json($response->json(), 200);

            } else {
                // Handle unknown request type
                $msg = [
                    'error' => 'Unknown request type',
                    'request' => $request->all()
                ];
                $this->log($msg, 'webhookErrors.log');
                return response()->json(['error' => 'Unknown request type'], 200);
            }
        } catch (\Exception $e) {
            // Handle exceptions
            $msg = [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ];
            $this->log($msg, 'webhookErrors.log');
            // Returning 200 response always to avoid health check failures
            return response()->json(['error' => 'An error occurred'], 200);
        }
    }

    // Send Responses
    public function sendMessage(Request $request) {
        // Log Received Requests
        $this->log($request->all(), 'sendMessage.log');

        try {
            // Get channel bassed on phone number format
            $channel = preg_match('/^\+?\d{1,3}\d+$/', $request['to']) ? 'whatsapp' : 'brandmessenger';
            // Check if Hands Off is requested
            if(isset($request['whatsapp']['text']) && $request['whatsapp']['text'] === 'HANDS OFF') {
                // Create Hands Off Json
                $json = format::handsOff($request, $channel);
                if(!$json) {
                    return response()->json(['error' => 'Failed to create Hands Off JSON'], 200);
                }

                // Send Hands Off request to externalFunctions
                $handOffResponse = externalFunctions::handsOff($json);
                if(!$handOffResponse) {
                    return response()->json(['error' => 'Failed to send Hands Off request'], 200);
                }

                // Create Workqueue Change Json
                $json = format::changeWorkqueue($request, $channel, 'AGENT');
                if(!$json) {
                    return response()->json(['error' => 'Failed to create Workqueue Change JSON'], 200);
                }

                // Send Workqueue Change request to externalFunctions
                $workQueueResponse = externalFunctions::changeWorkqueue($json);
                if(!$workQueueResponse) {
                    return response()->json(['error' => 'Failed to send Workqueue Change request'], 200);
                }

                return response()->json([
                    'Message' => 'Multi-function request',
                    'request_type' => 'agent_handoff',
                    'responses' => [
                        'handoff' => $handOffResponse,
                        'workqueue' => $workQueueResponse,
                    ],
                    'errors' => []
                ]);
            // Check if Gen AI is Request
            } else if($request['whatsapp']['contentType'] === 'df_answer') {

                // Send request to Gen AI API
                $response = Http::withHeaders([
                    'Content-Type' => 'application/json',
                    'Authorization' => env("GEN_AI_TOKEN"),
                ])->post(('botinfo.externalGenAI') . '/gen-ai/invoke', [
                    'query' => $request["whatsapp"]["text"],
                    'user_id' => $request["whatsapp"]["from"],
                    'conversation_id' => $request["conversation_id"],
                    'platform' => $request["platform"],
                ]);
                
                // Handle Response
                if ($response->successful()) {
                    return response()->json($response->json(), 200);
                } else {
                    $endLog->info('genAiErrors.log',[$response->json(), $response->status()]);
                    return response()->json($response->json(), $response->status());
                }

            // Check if response is returned from Chatbot
            } else if(isset($request['whatsapp'])) {

                // Convert to externalFunctions Request
                $json = format::sendMessage($request, $channel);

                // Get token from config if not expired or call getToken API if expired
                $token = config('botinfo.externalTokenExpiry') > Carbon::now()->timestamp * 1000 ? config('botinfo.externalToken') : externalFunctions::getToken();
                if(!$token) {
                    return response()->json($json, 200);
                }

                // Send the message to externalFunctions
                $response = Http::withHeaders([
                                'Authorization' => 'Bearer ' . $token,
                            ])->post(config('botinfo.externalUrl') . '/bots/v3/respond', $json);
                return response()->json($response->json(), 200);

            // if none of the above conditions are met
            } else {
                // Handle unknown response type
                $msg = [
                    'error' => 'Unknown response type',
                    'response' => $response
                ];
                $this->log($msg, 'sendMessageErrors.log');
                return response()->json(['error' => 'Unknown response type'], 200);

            }
        } catch (\Exception $e) {
            // Handle exceptions
            $msg = [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ];
            $this->log($msg, 'sendMessageErrors.log');
            // Returning 200 response always to avoid health check failures
            return response()->json(['error' => 'An error occurred'], 200);
        }

    }

    // Logging
    public function log($message, $file) {
        $endLog = new Logger("messages");
        
        // File name
        $endLog->pushHandler(new StreamHandler(storage_path("logs/{$file}")), Logger::INFO);
        
        // Items to be logged
        $endLogMsg = [$message];
        $endLog->info('Logs Generated by gatewayController', $endLogMsg);

        return "Logged successfully";
    }
}
