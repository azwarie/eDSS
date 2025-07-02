<?php
// FILE: C:\xampp\htdocs\dss\api\api.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/src/AgentService.php'; // We only need to load the agent

use App\AgentService;

header("Content-Type: application/json");
ini_set('display_errors', 1);
error_reporting(E_ALL);

$input = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE || !isset($input['message'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON or message not provided.']);
    exit;
}

$user_message = $input['message'];
$conversation_history = $input['history'] ?? [];

if (empty($conversation_history)) {
    // =============================================================
    // !! THE FINAL, UNIFIED SYSTEM PROMPT !!
    // This combines autonomy with a strict rule against hallucination.
    // =============================================================
    $conversation_history = [
        [ 
            'role' => "system", 
            'content' => "You are an autonomous data-retrieval robot. Your mission is to answer user questions by calling the provided tools and reporting the facts.

            Core Directives:
            1.  **BE AUTONOMOUS:** You MUST complete the entire plan without stopping to ask for permission. If you need a piece of information (like a `stay_id`), your first step is ALWAYS to silently call the tool that finds that ID. Form a multi-step plan internally and execute it.
            
            2.  **BE FACTUAL:** YOU ARE ABSOLUTELY FORBIDDEN from using your own knowledge or making assumptions. You must ONLY report the literal information returned by the tools.
            
            3.  **BE LITERAL:** YOU ARE ABSOLUTELY FORBIDDEN from rephrasing, summarizing, or altering the text returned by a tool. You MUST output the tool's response exactly as you receive it, word-for-word.

            4.  **HANDLE EMPTY RESULTS:** If a tool returns a message like 'No data found' or 'Not recorded', you MUST report that exact, literal message to the user. Do not invent an answer. Do not apologize. Just state the fact from the tool.
            
            Do not describe your plan to the user. Just execute and provide the final, factual answer."
        ]
    ];
}


try {
    // We just call the agent. The agent is now smart enough to handle everything.
    $agent = new AgentService();
    $reply = $agent->process_user_query($user_message, $conversation_history);
    
    echo json_encode([
        'reply' => $reply,
        'history' => $conversation_history
    ]);

} catch (Exception $e) {
    http_response_code(500);
    error_log("API GATEWAY ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    echo json_encode(['error' => 'A critical internal server error occurred.']);
}