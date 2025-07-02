<?php
// FILE: C:\xampp\htdocs\dss\api\test_pinecone.php

// This script directly tests the PineconeTool class functions, bypassing the AI agent.
// Its purpose is to verify the technical correctness of the Pinecone queries.

ini_set('display_errors', 1);
error_reporting(E_ALL);

// Manually include the one file we need to test.
require_once __DIR__ . '/src/PineconeTool.php';

echo "<pre>"; // Use <pre> tags for clean output in a browser
echo "--- STARTING DIRECT PINECONE TEST SCRIPT ---<br>";
echo "=================================================<br><br>";

try {
    // Step 1: Instantiate the tool once for all tests.
    $pinecone_tool = new App\PineconeTool();
    echo "PineconeTool instantiated successfully.<br>";
    echo "------------------------------------<br><br>";


    // ========================================================
    // TEST CASE 1: Simple Symptom Search (your original test)
    // ========================================================
    echo "--- TEST 1: Running Simple Search ---<br>";
    $symptom_simple = "fever";
    echo "Testing find_stays_by_symptom() with symptom: '{$symptom_simple}'<br><br>";

    $result_simple = $pinecone_tool->find_stays_by_symptom($symptom_simple);

    echo "--- RAW RESULT FROM SIMPLE SEARCH ---<br>";
    print_r($result_simple);
    echo "<br>------------------------------------<br><br>";


    // ========================================================
    // TEST CASE 2: Advanced Filtered Search (the new test)
    // ========================================================
    echo "--- TEST 2: Running Advanced Filtered Search ---<br>";
    $symptom_advanced = "fever";
    
    // Define the filters we want to test. This is exactly what the agent would build.
    $filters_advanced = [
        'temperature' => ['$gt' => 37.5] // Find cases where temperature was greater than 37.5

    ];

    echo "Testing find_diagnoses_with_filters() with symptom: '{$symptom_advanced}'<br>";
    echo "And filters: " . json_encode($filters_advanced) . "<br><br>";

    // Call the new function directly
    $result_advanced = $pinecone_tool->find_diagnoses_with_filters($symptom_advanced, $filters_advanced);

    echo "--- RAW RESULT FROM FILTERED SEARCH ---<br>";
    print_r($result_advanced);
    echo "<br>------------------------------------<br><br>";


} catch (Exception $e) {
    echo "--- AN ERROR OCCURRED ---<br>";
    echo "Error: " . $e->getMessage();
}

echo "--- ALL TESTS COMPLETE ---";
echo "</pre>";