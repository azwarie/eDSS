<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
include('connection.php');

$result = [
    'totalBeds' => 0,
    'admissionData' => [],
    'admissionPerDay' => [],
    'dischargePerDay' => [],
    'activeAdmissions' => 0,
    'dischargedPatients' => 0,
];

// Fetch total bed count
$totalBedsResult = $conn2->query("SELECT COUNT(*) as total FROM azwarie_dss.BED");
if ($totalBedsResult) {
    $row = $totalBedsResult->fetch_assoc();
    $result['totalBeds'] = isset($row['total']) ? (int)$row['total'] : 0;
}

// Fetch admissions with diagnosis and discharge date
$admissions = $conn2->query("
    SELECT
        a.AdmissionID,
        a.AdmissionDateTime as date,
        a.PatientID,
        pa.DischargeDateTime as predictedDischargeDate
    FROM
        azwarie_dss.ADMISSION a
    LEFT JOIN
        azwarie_dss.PAST_ADMISSION pa ON a.AdmissionID = pa.AdmissionID
    WHERE a.AdmissionDateTime >= NOW() - INTERVAL 1 YEAR
    ORDER BY a.AdmissionDateTime DESC
");

$patient_names = [];
$diagnosis_data = [];
if ($admissions) {
    while ($row = $admissions->fetch_assoc()) {
        $patientID = $row['PatientID'];
        
           if (!isset($patient_names[$patientID])) {
               $patientResult = $conn2->query("SELECT PatientName, DiagnosisID FROM `123`.`patients` WHERE PatientID = '$patientID'");
               if ($patientResult && $patientRow = $patientResult->fetch_assoc()) {
                   $patient_names[$patientID] = [
                       'patientName' => $patientRow['PatientName'],
                       'diagnosisID' => $patientRow['DiagnosisID'],
                    ];
               } else {
                   $patient_names[$patientID] = ['patientName' => "Unknown Patient", 'diagnosisID' => null];
               }
           }
        $diagnosisID = $patient_names[$patientID]['diagnosisID'];
        if (!isset($diagnosis_data[$diagnosisID])) {
              $diagnosisResult = $conn2->query("SELECT DiagnosisName, EstimatedLengthOfStay FROM `123`.`DIAGNOSIS` WHERE DiagnosisID = '$diagnosisID'");
                if ($diagnosisResult && $diagnosisRow = $diagnosisResult->fetch_assoc()) {
                    $diagnosis_data[$diagnosisID] = [
                       'diagnosisName' => $diagnosisRow['DiagnosisName'],
                       'estimatedLengthOfStay' => $diagnosisRow['EstimatedLengthOfStay']
                    ];
                } else {
                   $diagnosis_data[$diagnosisID] = ['diagnosisName' =>  "Unknown Diagnosis", 'estimatedLengthOfStay' => 0];
                }
          }
         $predictedDischargeDate = $row['predictedDischargeDate'];
        if($predictedDischargeDate === null)
        {
            $admissionDate = new DateTime($row['date']);
             $estimatedStay =  $diagnosis_data[$diagnosisID]['estimatedLengthOfStay'];
             $admissionDate->modify("+$estimatedStay day");
             $predictedDischargeDate = $admissionDate->format('Y-m-d H:i:s');
        }
           $result['admissionData'][] = [
            'date' => isset($row['date']) ? date('Y-m-d', strtotime($row['date'])) : null,
             'patientName' =>  $patient_names[$patientID]['patientName'],
            'diagnosisName' => $diagnosis_data[$diagnosisID]['diagnosisName'],
             'predictedDischargeDate' => $predictedDischargeDate,
        ];
    }
}

// Fetch total number of admissions per day
$admissionPerDay = $conn2->query("
    SELECT
      DATE(AdmissionDateTime) as Date, COUNT(*) as Admissions
    FROM azwarie_dss.ADMISSION
    WHERE AdmissionDateTime >= NOW() - INTERVAL 7 DAY
    GROUP BY DATE(AdmissionDateTime)
    ORDER BY Date DESC
");

if ($admissionPerDay) {
   while ($row = $admissionPerDay->fetch_assoc()) {
    $result['admissionPerDay'][$row['Date']] = isset($row['Admissions']) ? (int)$row['Admissions'] : 0;
  }
}

    // Calculate predicted discharges per day
   $predictedDischargePerDay = [];
   foreach ($result['admissionData'] as $admission) {
        if ($admission['predictedDischargeDate']) {
            $dischargeDate = date('Y-m-d', strtotime($admission['predictedDischargeDate']));
            if (isset($predictedDischargePerDay[$dischargeDate])) {
                $predictedDischargePerDay[$dischargeDate]++;
           } else {
                $predictedDischargePerDay[$dischargeDate] = 1;
            }
       }
    }
   $result['dischargePerDay'] = $predictedDischargePerDay;


// Calculate active admissions
$activeAdmissionsResult = $conn2->query("SELECT COUNT(*) as active FROM azwarie_dss.ADMISSION WHERE DischargeDateTime IS NULL");
if ($activeAdmissionsResult) {
    $row = $activeAdmissionsResult->fetch_assoc();
   $result['activeAdmissions'] = isset($row['active']) ? (int)$row['active'] : 0;
}

// Calculate discharged patients
$dischargedPatientsResult = $conn2->query("SELECT COUNT(*) as discharged FROM azwarie_dss.PAST_ADMISSION WHERE DischargeDateTime IS NOT NULL");
if ($dischargedPatientsResult) {
    $row = $dischargedPatientsResult->fetch_assoc();
    $result['dischargedPatients'] = isset($row['discharged']) ? (int)$row['discharged'] : 0;
}

header('Content-Type: application/json');
$json_output = json_encode($result);
if ($json_output === false)
{
   echo json_encode(['error' => 'json_encode failed']);
}
else {
   echo $json_output;
}
?>