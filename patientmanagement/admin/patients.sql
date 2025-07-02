CREATE TABLE patients (
    id INT AUTO_INCREMENT PRIMARY KEY,    
    name VARCHAR(100) NOT NULL,           
    ic VARCHAR(20) NOT NULL UNIQUE,       
    gender ENUM('Male', 'Female', 'Other') 
    phone_number VARCHAR(15) NOT NULL,    
    address TEXT NOT NULL,                
    email VARCHAR(100) NOT NULL UNIQUE,   
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, 
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP 
);


INSERT INTO patients (name, ic, gender, phone_number, address, email)
VALUES
('Navinkumaar', '800101-01-1234', 'Male', '0123456789', 'No 21, Jalan Taman Rimba, Mentakab, Pahang', 'navinkumaar@gmail.com'),
('Afiqah', '900202-02-5678', 'Female', '0198765432', 'No 9, Jalan Permai 1/2, Mentakab, Pahang', 'afiqah@gmail.com'),
('MoganRaj', '950303-03-9101', 'Male', '0189988776', 'No 5, Jalan Saga 2, Mentakab, Pahang', 'moganraj@gmail.com');
