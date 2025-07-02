<?php
// FILE: C:\xampp\htdocs\dss\api\src\AgentService.php
namespace App;

require_once __DIR__ . '/DatabaseTool.php';
require_once __DIR__ . '/PineconeTool.php';

class AgentService
{
    private DatabaseTool $db_tool;
    private PineconeTool $pinecone_tool;
    private array $tools_for_google;
    private string $google_api_key;
    private string $google_model_name = 'gemini-1.5-flash-latest';

    public function __construct()
    {
        $this->google_api_key = "AIzaSyAWYls6qtmS07GJ02JwdPsv2IIOWm8luic"; // <-- Make sure to use your valid key
        $this->db_tool = new DatabaseTool();
        $this->pinecone_tool = new PineconeTool();

        // This is the final, complete toolbox with rich, guiding descriptions.
        $this->tools_for_google = [
            'function_declarations' => [
                
                // --- Pinecone Tools ---
                ['name' => 'find_stays_by_symptom', 
                 'description' => "Use for HYPOTHETICAL questions about a general symptom when NO specific patient is being discussed. Input must be ONLY the symptom.", 
                 'parameters' => ['type' => 'OBJECT','properties' => ['symptom_description' => ['type' => 'string']] ,'required' => ['symptom_description']]],
                 
                ['name' => 'find_diagnoses_with_filters', 
                 'description' => "Use for complex HYPOTHETICAL questions that include both a symptom AND specific filters for vital signs (like temperature, heartrate) or pain score.", 
                 'parameters' => [ 'type' => 'OBJECT', 'properties' => [ 'symptom' => ['type' => 'string'], 'filters' => [ 'type' => 'OBJECT', 'description' => "A JSON object for filters like {'temperature': {'\$gt': 38}}."]], 'required' => ['symptom']]],

                // --- Database (MySQL) Tools ---
                ['name' => 'get_diagnoses_for_stay', 
                 'description' => "Gets a full diagnosis summary for a SPECIFIC patient's stay, connecting their Chief Complaint to their Final Diagnoses. Requires a `stay_id`.", 
                 'parameters' => ['type' => 'OBJECT','properties' => ['stay_id' => ['type' => 'string']], 'required' => ['stay_id']]],
                 
                ['name' => 'get_assigned_staff', 
                 'description' => "Finds staff assigned to a patient's stay. You can optionally filter by a specific role like 'DiagnosingDoctor', 'TriageStaff', or 'AttendingED' to answer specific questions like 'who diagnosed the patient?'.", 
                 'parameters' => ['type' => 'OBJECT', 'properties' => ['stay_id' => ['type' => 'string'], 'role' => ['type' => 'string', 'description' => "Optional. The specific role to filter by."]], 'required' => ['stay_id']]],
                 
                ['name' => 'get_stay_id_from_patient_id', 
                 'description' => "A critical first step. Call this to get a `stay_id` from a `patient_id` before getting diagnoses, triage details, or staff info.", 
                 'parameters' => ['type' => 'OBJECT','properties' => ['patient_id' => ['type' => 'string']],'required' => ['patient_id']]],
                 
                ['name' => 'get_patient_details', 
                 'description' => "Gets a patient's demographic info like name, gender, and race. Requires a `patient_id`.", 
                 'parameters' => ['type' => 'OBJECT','properties' => ['patient_id' => ['type' => 'string']],'required' => ['patient_id']]],
                 
                ['name' => 'get_ed_stay_details', 
                 'description' => "Gets all clinical triage information for a specific visit, including the chief complaint, acuity, pain score, and all vital signs (vitals). Requires a `stay_id`.", 
                 'parameters' => ['type' => 'OBJECT','properties' => ['stay_id' => ['type' => 'string']],'required' => ['stay_id']]],
                 
                ['name' => 'get_current_patient_count', 
                 'description' => 'Gets the current total number of active patients in the ED.', 
                 'parameters' => ['type' => 'OBJECT', 'properties' => new \stdClass()]],
                 
                ['name' => 'get_patient_id_by_name', 
                 'description' => "Finds a patient's ID by their full or partial name. Use this as a first step if you only have a name.", 
                 'parameters' => ['type' => 'OBJECT','properties' => ['patient_name' => ['type' => 'string']] ,'required' => ['patient_name']]],
                
['name' => 'get_patients_by_diagnosis', 
 'description' => "Finds a list of all patients who have been given a certain final diagnosis. The user can search by providing either the diagnosis code (e.g., 'R0789') or the diagnosis title (e.g., 'Other chest pain').", 
 'parameters' => [
     'type' => 'OBJECT',
     'properties' => [
         'search_term' => [
             'type' => 'string', 
             'description' => 'The ICD code or full/partial diagnosis title to search for.'
         ]
     ],
     // --- THE FIX IS HERE ---
     // 'required' is now correctly placed inside the 'parameters' object.
     'required' => ['search_term']
 ]
],
            ]
        ];
    }
    
    private function callGoogleApi(array $payload): array {
        // This function is correct and does not need to change.
        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$this->google_model_name}:generateContent?key={$this->google_api_key}";
        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        $response_json = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code !== 200 || !$response_json) { 
            throw new \Exception("Google API call failed with status {$http_code}: " . $response_json); 
        }
        $data = json_decode($response_json, true);
        if (!isset($data['candidates'][0]['content'])) { 
            if (isset($data['promptFeedback']['blockReason'])) { 
                throw new \Exception("Request was blocked. Reason: " . $data['promptFeedback']['blockReason']); 
            }
            throw new \Exception("Invalid JSON response from Google: " . $response_json); 
        }
        return $data['candidates'][0]['content'];
    }

    public function process_user_query(string $query, array &$conversation_history): string {
        try {
            $api_history = [];
            foreach ($conversation_history as $message) { 
                $role = ($message['role'] === 'assistant') ? 'model' : 'user'; 
                $api_history[] = ['role' => $role, 'parts' => [['text' => $message['content']]]]; 
            }
            $api_history[] = ['role' => 'user', 'parts' => [['text' => $query]]];

            while (true) {
                $payload = ['contents' => $api_history, 'tools' => [$this->tools_for_google]];
                $response_content = $this->callGoogleApi($payload);
                $api_history[] = $response_content;

                if (isset($response_content['parts'][0]['functionCall'])) {
                    // ========================================================
                    // !! THE BUG FIX IS HERE !!
                    // This new loop correctly handles one OR MORE function calls from the API.
                    // ========================================================
                    $tool_response_parts = [];
                    // Iterate over every "part" in the response.
                    foreach ($response_content['parts'] as $function_call_part) {
                        // We only process parts that are actual function calls.
                        if (!isset($function_call_part['functionCall'])) continue;

                        $function_call = $function_call_part['functionCall'];
                        $function_name = $function_call['name'];
                        $arguments = $function_call['args'] ?? [];
                        
                        $tool_instance = null;
                        if (method_exists($this->db_tool, $function_name)) { 
                            $tool_instance = $this->db_tool; 
                        } elseif (method_exists($this->pinecone_tool, $function_name)) { 
                            $tool_instance = $this->pinecone_tool; 
                        }

                        if ($tool_instance) {
                            $tool_result = call_user_func_array([$tool_instance, $function_name], $arguments);
                        } else {
                            $tool_result = "Error: Agent does not have the tool '{$function_name}'.";
                        }
                        
                        // Add the result of THIS specific tool call to our list of responses.
                        $tool_response_parts[] = [
                            'functionResponse' => [
                                'name' => $function_name, 
                                'response' => ['name' => $function_name, 'content' => $tool_result]
                            ]
                        ];
                    }
                    
                    // Add the entire list of tool responses back to the history for the next turn.
                    $api_history[] = ['role' => 'tool', 'parts' => $tool_response_parts];
                    continue; 
                }

                $final_answer = $response_content['parts'][0]['text'] ?? "I'm sorry, I could not process that request.";
                $conversation_history[] = ['role' => 'user', 'content' => $query];
                $conversation_history[] = ['role' => 'assistant', 'content' => $final_answer];
                return $final_answer;
            }
        } catch (\Exception $e) {
            error_log("CHATBOT AGENT ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return "Sorry, I encountered a critical agent error. Please check the server logs.";
        }
    }
}