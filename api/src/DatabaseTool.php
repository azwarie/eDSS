<?php
// FILE: C:\xampp\htdocs\dss\api\src\DatabaseTool.php

namespace App;

use PDO;
use PDOException;

class DatabaseTool
{
    private ?PDO $pdo = null;

    public function __construct()
    {
        $host = '127.0.0.1';
        $db   = 'azwarie_dss';
        $user = 'root';
        $pass = '';
        $charset = 'utf8mb4';

        $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $this->pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            error_log("DATABASE CONNECTION FAILED: " . $e->getMessage());
            throw new PDOException("Database connection failed.");
        }
    }

    public function get_current_patient_count(): int
    {
        $sql = "SELECT COUNT(*) as patient_count FROM EDSTAYS WHERE Outime IS NULL";
        $stmt = $this->pdo->query($sql);
        return (int) $stmt->fetch()['patient_count'];
    }

    public function get_patient_details(?string $patient_id = null): string
    {
        if (empty($patient_id)) return "Error: A patient_id must be provided.";
        $sql = "SELECT PatientID, PatientName, Gender, Race, Phone_Number, Address, Email FROM PATIENTS WHERE PatientID = :patient_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['patient_id' => $patient_id]);
        $result = $stmt->fetch();
        if (!$result) return "Error: No patient found with ID {$patient_id}.";
        $details = "Patient Details Found:\n";
        foreach ($result as $key => $value) { $details .= "- " . str_replace('_', ' ', $key) . ": " . $value . "\n"; }
        return trim($details);
    }

public function get_ed_stay_details(?string $stay_id = null): string
{
    if (empty($stay_id)) return "Error: A stay_id must be provided.";
    
    $sql = "SELECT e.StayID, et.ChiefComplaint, t.Temperature, t.Heartrate, t.Resprate, t.O2sat, t.SBP, t.DBP, t.Pain, t.Acuity 
            FROM edstays e 
            LEFT JOIN edstays_triage et ON e.StayID = et.StayID 
            LEFT JOIN triage t ON et.TriageID = t.TriageID 
            WHERE e.StayID = :stay_id";
    
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute(['stay_id' => $stay_id]);
    $result = $stmt->fetch();

    if (!$result) return "Error: No stay found with ID {$stay_id}.";

    // --- Build the new, beautifully formatted response ---
    
    $response_lines = [];
    $response_lines[] = "Triage Information for Stay ID {$stay_id}:";

    // Add Chief Complaint
    if (!empty($result['ChiefComplaint'])) {
        $response_lines[] = "- Chief Complaint: " . $result['ChiefComplaint'];
    }

    // Add Acuity
    if (isset($result['Acuity'])) {
        $response_lines[] = "- Acuity: " . $result['Acuity'];
    }

    // Create an indented section for Vitals
    $vitals_lines = [];
    if (isset($result['Temperature'])) $vitals_lines[] = "  - Temperature: " . $result['Temperature'] . " °C";
    if (isset($result['Heartrate'])) $vitals_lines[] = "  - Heart Rate: " . $result['Heartrate'] . " bpm";
    if (isset($result['Resprate'])) $vitals_lines[] = "  - Respiratory Rate: " . $result['Resprate'] . " breaths/min";
    if (isset($result['O2sat'])) $vitals_lines[] = "  - O2 Saturation: " . $result['O2sat'] . "%";
    if (isset($result['SBP']) && isset($result['DBP'])) $vitals_lines[] = "  - Blood Pressure: " . $result['SBP'] . "/" . $result['DBP'] . " mmHg";

    if (!empty($vitals_lines)) {
        $response_lines[] = "- Vitals:";
        $response_lines = array_merge($response_lines, $vitals_lines);
    }
    
    // Add Pain Score
    if (isset($result['Pain'])) {
        $response_lines[] = "- Pain Score: " . $result['Pain'];
    }

    // Join all lines with a newline character
    return implode("\n", $response_lines);
}
    
    public function get_stay_id_from_patient_id(?string $patient_id = null): string
    {
        if (empty($patient_id)) return "Error: A patient_id must be provided.";
        $sql = "SELECT StayID FROM EDSTAYS WHERE PatientID = :patient_id ORDER BY Intime DESC LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['patient_id' => $patient_id]);
        $result = $stmt->fetch();
        return $result ? $result['StayID'] : "Error: No stay found for patient {$patient_id}.";
    }

public function get_assigned_staff(?string $stay_id = null, ?string $role = null): string
{
    if (empty($stay_id)) {
        return "Error: A stay_id must be provided to find assigned staff.";
    }

    // Start building the SQL query
    $sql = "SELECT s.Name, r.Role as AssignedRole 
            FROM edstay_staff_roles r
            JOIN STAFF s ON r.StaffID = s.StaffID
            WHERE r.StayID = :stay_id";

    $params = ['stay_id' => $stay_id];

    // If a specific role is requested, add it to the query
    if (!empty($role)) {
        $sql .= " AND r.Role = :role";
        $params['role'] = $role;
    }
    
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();

    if (empty($results)) {
        if (!empty($role)) {
            return "No staff member with the role '{$role}' was found for stay {$stay_id}.";
        }
        return "No staff were found assigned to stay {$stay_id}.";
    }
    
    $staffStrings = [];
    foreach ($results as $row) {
        $staffStrings[] = $row['Name'] . ' (Role: ' . $row['AssignedRole'] . ')';
    }
    
    $prefix = !empty($role) ? "Staff with role '{$role}': " : "Assigned Staff: ";
    return $prefix . implode('; ', $staffStrings);
}
    
public function get_diagnoses_for_stay(?string $stay_id = null): string
{
    if (empty($stay_id)) {
        return "Error: A stay_id must be provided.";
    }

    $sql = "SELECT d.ICD_Code, d.ICD_Title 
            FROM EDSTAYS_DIAGNOSIS ed 
            JOIN DIAGNOSIS d ON ed.ICD_Code = d.ICD_Code 
            WHERE ed.StayID = :stay_id";
            
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute(['stay_id' => $stay_id]);
    $results = $stmt->fetchAll();

    // --- THIS IS THE CRITICAL FIX ---
    // If the database query returns no results, we return a forceful,
    // undeniable string that the agent cannot misinterpret.
    if (empty($results)) {
        // This specific wording prevents the agent from trying to be "helpful".
        return "DATABASE QUERY RESULT: No final diagnoses have been recorded for stay ID {$stay_id}.";
    }
    
    // If we DO have results, we format them as before.
    $diagStrings = [];
    foreach ($results as $row) {
        $diagStrings[] = $row['ICD_Title'] . ' (' . $row['ICD_Code'] . ')';
    }
    
    return "Final Diagnoses Found: " . implode('; ', $diagStrings);
}

    public function get_patient_id_by_name(?string $patient_name = null): string
    {
        if (empty($patient_name)) return "Error: A patient_name must be provided.";
        $sql = "SELECT PatientID, PatientName FROM PATIENTS WHERE PatientName LIKE :patient_name LIMIT 5";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['patient_name' => '%' . $patient_name . '%']);
        $results = $stmt->fetchAll();
        if (empty($results)) return "Error: No patients found matching the name '{$patient_name}'.";
        $patientStrings = [];
        foreach ($results as $row) { $patientStrings[] = $row['PatientName'] . ' (ID: ' . $row['PatientID'] . ')'; }
        return "Matching Patients: " . implode('; ', $patientStrings);
    }
    
     public function getPDO(): ?PDO
    {
        return $this->pdo;
    }

public function get_patients_by_diagnosis(?string $search_term = null): string
{
    if (empty($search_term)) {
        return "Error: A diagnosis code or title must be provided.";
    }

    // This is the core logic: search by code OR by title.
    // The `LIKE` operator with `%%` allows for partial title matches.
    $lookup_sql = "SELECT ICD_Code, ICD_Title FROM DIAGNOSIS 
                   WHERE ICD_Code = :search_term OR ICD_Title LIKE :like_search_term ";
                   
    $lookup_stmt = $this->pdo->prepare($lookup_sql);
    $lookup_stmt->execute([
        'search_term' => $search_term,
        'like_search_term' => '%' . $search_term . '%'
    ]);
    $diagnosis_info = $lookup_stmt->fetch();

    if (!$diagnosis_info) {
        return "The diagnosis '{$search_term}' could not be found in the database.";
    }

    // Now we have the official code and title, regardless of what the user searched for.
    $icd_code = $diagnosis_info['ICD_Code'];
    $icd_title = $diagnosis_info['ICD_Title'];

    // --- The rest of the function proceeds as before, but using the confirmed ICD code ---

    $count_sql = "SELECT COUNT(DISTINCT p.PatientID) as total FROM PATIENTS p JOIN EDSTAYS es ON p.PatientID = es.PatientID JOIN EDSTAYS_DIAGNOSIS ed ON es.StayID = ed.StayID WHERE ed.ICD_Code = :icd_code";
    $count_stmt = $this->pdo->prepare($count_sql);
    $count_stmt->execute(['icd_code' => $icd_code]);
    $total_count = (int) $count_stmt->fetchColumn();

    if ($total_count === 0) {
        return "No patients were found with the diagnosis '{$icd_title}' ({$icd_code}).";
    }

    $sql = "SELECT DISTINCT p.PatientID, p.PatientName, ed.StayID FROM PATIENTS p JOIN EDSTAYS es ON p.PatientID = es.PatientID JOIN EDSTAYS_DIAGNOSIS ed ON es.StayID = ed.StayID WHERE ed.ICD_Code = :icd_code";
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute(['icd_code' => $icd_code]);
    $results = $stmt->fetchAll();

    $response_lines = [];
    $response_lines[] = "Found a total of {$total_count} patients diagnosed with '{$icd_title}' ({$icd_code}). Here is the full list:";
    foreach ($results as $row) {
        $response_lines[] = "- " . $row['PatientName'] . " (PatientID: " . $row['PatientID'] . ", StayID: " . $row['StayID'] . ")";
    }

    return implode("\n", $response_lines);
}
    
}


   
