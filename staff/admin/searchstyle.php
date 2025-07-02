<?php
// Get the current page filename
$current_page = basename($_SERVER['PHP_SELF']);
?>

<style>
    /* Style for the form */
    #searchForm {
        display: flex;
        justify-content: flex-end; /* Align form to the right */
        align-items: center; /* Align vertically */
        width: 100%; /* Full-width for the form */
        margin-top: 20px; /* Space from the top */
        margin-bottom: 10px; /* Space from the top */
    }
    /* Input container for the magnifying glass */
    .input-container {
        display: flex;
        justify-content: center;
        align-items: center; /* Center align the input and icon */
        position: relative;
        width: 100%;
        max-width: 400px; /* Limit width for the input container */
    }
    .input-container i {
        position: absolute;
        left: 15px;
        transform: translateY(-50%);
        font-size: 16px;
        color: #007bff;
    }
    /* Styling the input field */
    input[type="text"] {
        padding: 10px 10px 10px 40px; /* Space for the magnifying glass */
        border: 1px solid #ccc;
        border-radius: 20px;
        width: 100%;
        font-size: 19px;
    }
    input[type="text"]::placeholder {
        color: #777;
    }
    /* Focus styling for the input */
    input[type="text"]:focus {
        border-color: #007bff;
        outline: none;
        box-shadow: 0 0 5px rgba(0, 123, 255, 0.5);
    }
    /* Clear icon styling */
    #clearIcon {
        position: absolute;
        right: 15px; /* Position to the right of the input */
        transform: translateY(-50%);
        top: 50%; /* Center vertically */
        font-size: 18px;
        color: #007bff;
        cursor: pointer;
        display: none; /* Initially hidden */
    }
    #clearIcon:hover {
        color: #0056b3; /* Darker blue on hover */
    }
    /* Style for the search button */
    button {
        font-size: 16px;
        justify-content: space-between;
        background-color: #007bff;
        color: white;
        padding: 8px; /* Increase padding for better UX */
        border: none;
        border-radius: 5px; /* Match the input field's rounded corners */
        cursor: pointer;
        transition: background-color 0.3s ease;            
    }
    button:hover {
        background-color:rgb(5, 73, 146);
    }
    p[style*="color: green;"] {
        background-color: #d4edda;
    }
    p[style*="color: red;"] {
        background-color: #f8d7da;
    }
    /* Responsive table layout */
    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: -10px;
    }
    table th, table td {
        text-align: left;
    }
    th.clickable {
        cursor: pointer; /* Makes it look clickable */
        color: #000000; /* Blue color for the header */
        text-decoration: underline; /* Underline on hover */
    }
    th.clickable:hover {
        color: #007bff; /* Darker blue when hovered */
    }
    /* Hero section styling */
    .hero-section {
            background-color: #007bff;
            color: white;
            padding: 80px 0;
            text-align: center;
        }
        .hero-section h1 {
            font-family: 'Montserrat', sans-serif;
            font-size: 3.5rem;
            font-weight: 700;
            color: white;
            text-transform: uppercase;
        }
        .hero-section p {
            font-size: 1.2rem;
        }
            /* Ensure responsiveness on smaller screens */
                @media (max-width: 768px) {
                .card-responsive {
                    margin: 10px;
                    padding: 10px;
                }
                .hero-section h1 {
                    font-size: 2.5rem;
                }
                .hero-section p {
                    font-size: 1rem;
                }
            }
</style>