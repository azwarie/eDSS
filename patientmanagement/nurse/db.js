const mysql = require('mysql2');

const db = mysql.createConnection({
    host: 'localhost',
    user: 'root',         // Replace with your MariaDB username
    password: '1234567',         // Replace with your MariaDB password
    database: 'workshop2'
});

db.connect(err => {
    if (err) {
        console.error('Error connecting to MariaDB:', err);
        return;
    }
    console.log('Connected to MariaDB!');
});

module.exports = db;
