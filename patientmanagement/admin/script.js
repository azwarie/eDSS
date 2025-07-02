// Example: Add handling for patient registration
document.getElementById('patient-form').addEventListener('submit', function (event) {
    event.preventDefault();
    alert('Patient Registered Successfully!');
    this.reset();
});

// Add similar logic for record, appointment, and billing forms
document.getElementById('record-form').addEventListener('submit', function (event) {
    event.preventDefault();
    alert('Patient Record Saved Successfully!');
    this.reset();
});

document.getElementById('appointment-form').addEventListener('submit', function (event) {
    event.preventDefault();
    alert('Appointment Scheduled Successfully!');
    this.reset();
});

document.getElementById('billing-form').addEventListener('submit', function (event) {
    event.preventDefault();
    alert('Invoice Generated Successfully!');
    this.reset();
});
