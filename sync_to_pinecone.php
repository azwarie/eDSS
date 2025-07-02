<?php
// FILE: C:\xampp\htdocs\dss\sync_to_pinecone.php

// Set a long execution time because this might take a while
ini_set('max_execution_time', 300); // 5 minutes

// We need to include the files that define our classes
require_once __DIR__ . '/api/src/DatabaseTool.php';
require_once __DIR__ . '/api/src/PineconeTool.php';

echo "--- Pinecone Sync Script ---\n";

try {
    // We will use the tool classes to access their methods
    $dbTool = new App\DatabaseTool();
    $pineconeTool = new App\PineconeTool();
    
    // Get the raw PDO connection object from our DatabaseTool
    $pdo = $dbTool->getPDO();
    
    if (!$pdo) {
        echo "Error: Could not get database connection.\n";
        exit(1); // Exit with an error code
    }

    // ==========================================================
    // !! THIS IS THE CORRECTED SQL QUERY !!
    // It directly selects from the EDSTAYS_TRIAGE table.
    // ==========================================================
    $sql = "SELECT StayID, ChiefComplaint FROM EDSTAYS_TRIAGE WHERE ChiefComplaint IS NOT NULL AND ChiefComplaint != ''";
    
    $stmt = $pdo->query($sql);
    $complaints = $stmt->fetchAll();

    if (empty($complaints)) {
        echo "No complaints found in the EDSTAYS_TRIAGE table to sync.\n";
        exit(0);
    }
    
    echo "Found " . count($complaints) . " complaints to process.\n";

    $vectors_to_upsert = [];
    $batch_size = 50; // Upsert in batches of 50 to respect API and memory limits
    $count = 0;

    foreach ($complaints as $complaint) {
        $count++;
        $stay_id = $complaint['StayID'];
        $text = $complaint['ChiefComplaint'];

        // Skip if for some reason text is empty
        if (empty(trim($text))) {
            continue;
        }

        echo "Processing #{$count}: [StayID: {$stay_id}] - {$text}\n";
        
        // Get the vector embedding for the complaint text
        $embedding = $pineconeTool->getEmbedding($text);

        if ($embedding) {
            $vectors_to_upsert[] = [
                'id' => 'stay-' . $stay_id, // Create a unique ID for Pinecone
                'values' => $embedding,
                'metadata' => [
                    'text' => $text,
                    'stay_id' => $stay_id
                ]
            ];
        } else {
            echo "  -> FAILED to get embedding for this item. Skipping.\n";
        }

        // When the batch is full, or it's the last item, send it to Pinecone
        if (count($vectors_to_upsert) >= $batch_size || ($count === count($complaints) && !empty($vectors_to_upsert))) {
            echo "  -> Upserting batch of " . count($vectors_to_upsert) . " vectors...\n";
            $success = $pineconeTool->upsert($vectors_to_upsert);
            if ($success) {
                echo "  -> Batch upsert successful!\n";
            } else {
                echo "  -> !! BATCH UPSERT FAILED !!\n";
            }
            $vectors_to_upsert = []; // Reset the batch for the next set
        }

        // Sleep for a moment to be kind to the Google API and avoid rate limits
        sleep(1); 
    }

    echo "\n--- Sync Complete! ---\n";

} catch (Exception $e) {
    echo "\n--- AN ERROR OCCURRED ---\n";
    echo "Error: " . $e->getMessage() . "\n";
}