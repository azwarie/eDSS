<?php
// FILE: C:\xampp\htdocs\dss\api\src\PineconeTool.php

namespace App;

class PineconeTool
{
    private string $pinecone_api_key;
    private string $pinecone_endpoint;
    private string $google_api_key;

    public function __construct()
    {
        // Remember to use your new, valid keys
        $this->pinecone_api_key = "pcsk_7AaWSb_SxG1QbdvcYD3aBPRgDqsbnFdEBZGZNXX2tdxREkNxyPafA9bnHMMV44Fg2gdkVK";
        $this->pinecone_endpoint = "https://mimic-pinecone-nh2f2cz.svc.aped-4627-b74a.pinecone.io"; 
        $this->google_api_key = "AIzaSyAWYls6qtmS07GJ02JwdPsv2IIOWm8luic";
    }

    private function getEmbedding(string $text): ?array
    {
        $modelName = 'models/embedding-001';
        $endpoint = "https://generativelanguage.googleapis.com/v1beta/{$modelName}:embedContent?key={$this->google_api_key}";
        
        $payload = [
            'model' => $modelName, 
            'content' => ['parts' => [['text' => $text]]],
            // This task_type matches the Python script that uploaded the data, ensuring vector consistency.
            'task_type' => 'RETRIEVAL_DOCUMENT' 
        ];

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        $response_json = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($response_json, true);
        return $data['embedding']['values'] ?? null;
    }

    private function normalizeVector(array $vector): array
    {
        $sum_of_squares = 0.0;
        foreach ($vector as $value) {
            $sum_of_squares += $value * $value;
        }
        $norm = sqrt($sum_of_squares);
        if ($norm == 0) return $vector;
        $normalized_vector = [];
        foreach ($vector as $value) {
            $normalized_vector[] = $value / $norm;
        }
        return $normalized_vector;
    }
    
    /**
     * This is the new helper function that creates a consistent, formatted report.
     * Both search functions will now use this.
     */
    private function format_pinecone_results(array $matches, string $header_text): string
    {
        if (empty($matches)) {
            return "No matching cases were found.";
        }

        $response_lines = [];
        $response_lines[] = $header_text;
        
        $unique_check = [];
        $count = 0;

        foreach ($matches as $match) {
            // Stop after we have found 3 unique results.
            if ($count >= 3) {
                break;
            }

            $metadata = $match['metadata'] ?? [];
            $title = $metadata['icd_title'] ?? 'N/A';
            
            // This check prevents printing the same diagnosis twice in the same list.
            if (isset($unique_check[$title])) {
                continue;
            }
            $unique_check[$title] = true;

            // Gather all the details for the formatted string
            $chief_complaint = $metadata['chiefcomplaint'] ?? 'N/A';
            $code = $metadata['icd_code'] ?? 'N/A';
            $score = round(($match['score'] ?? 0) * 100);

            // Using your desired format from the Python script
            $response_lines[] = ($count + 1) . ". Chief Complaint: " . $chief_complaint . " - Diagnosis: " . $title . " (Code: " . $code . ", Score: " . $score . "%)";
            
            $count++;
        }

        return (count($response_lines) > 1) ? implode("\n", $response_lines) : "No matching diagnoses found for the given criteria.";
    }


    /**
     * The simple search function, now updated to use the formatter.
     */
    public function find_stays_by_symptom(string $symptom_description): string
    {
        if (empty($symptom_description)) return "Error: A symptom description is required.";
        
        $raw_vector = $this->getEmbedding($symptom_description);
        if (!$raw_vector) return "Error: Could not generate embedding.";
        $normalized_vector = $this->normalizeVector($raw_vector);

        // We get 5-10 results to have a better chance of finding 3 unique ones.
        $payload = ['vector' => $normalized_vector, 'topK' => 10, 'includeMetadata' => true, 'namespace' => 'ns1'];
        
        $ch = curl_init("{$this->pinecone_endpoint}/query");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Api-Key: ' . $this->pinecone_api_key]);
        $response_json = curl_exec($ch); curl_close($ch);
        $data = json_decode($response_json, true);

        // Call the new formatting helper function for a consistent look
        $header = "Top 3 diagnoses based on the chief complaint '{$symptom_description}':";
        return $this->format_pinecone_results($data['matches'] ?? [], $header);
    }

    /**
     * The advanced search function, also updated to use the formatter.
     */
    public function find_diagnoses_with_filters(string $symptom, ?array $filters = null): string
    {
        if (empty($symptom)) return "Error: A symptom is required.";

        $raw_vector = $this->getEmbedding($symptom);
        if (!$raw_vector) return "Error: Could not generate embedding.";
        $normalized_vector = $this->normalizeVector($raw_vector);

        $payload = [
            'vector'        => $normalized_vector,
            'topK'          => 20, // Get more results initially to allow for filtering
            'includeMetadata' => true,
            'namespace'     => 'ns1'
        ];
        if (!empty($filters)) {
            $payload['filter'] = $filters;
        }

        $ch = curl_init("{$this->pinecone_endpoint}/query");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Api-Key: ' . $this->pinecone_api_key]);
        $response_json = curl_exec($ch); curl_close($ch);
        $data = json_decode($response_json, true);

        // Call the same formatting helper function for a consistent look
        $header = "Top 3 diagnoses for '{$symptom}' matching the specified criteria:";
        return $this->format_pinecone_results($data['matches'] ?? [], $header);
    }
}