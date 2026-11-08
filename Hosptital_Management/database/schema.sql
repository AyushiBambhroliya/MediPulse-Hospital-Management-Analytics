
CREATE DATABASE IF NOT EXISTS medipulse
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE medipulse;

CREATE TABLE departments (
    department_id VARCHAR(10) PRIMARY KEY,
    department_name VARCHAR(100) NOT NULL,
    location VARCHAR(100)
);

CREATE TABLE doctors (
    doctor_id VARCHAR(10) PRIMARY KEY,
    doctor_name VARCHAR(100) NOT NULL,
    department_id VARCHAR(10),
    specialization VARCHAR(100),
    experience_years INT,
    FOREIGN KEY (department_id)
        REFERENCES departments(department_id)
);

CREATE TABLE patients (
    patient_id VARCHAR(10) PRIMARY KEY,
    patient_name VARCHAR(150) NOT NULL,
    age INT,
    gender VARCHAR(20),
    city VARCHAR(100),
    registration_date DATE
);

CREATE TABLE appointments (
    appointment_id VARCHAR(10) PRIMARY KEY,
    patient_id VARCHAR(10) NOT NULL,
    doctor_id VARCHAR(10) NOT NULL,
    appointment_date DATE,
    status VARCHAR(30),
    waiting_time_minutes INT,
    FOREIGN KEY (patient_id)
        REFERENCES patients(patient_id),
    FOREIGN KEY (doctor_id)
        REFERENCES doctors(doctor_id)
);

CREATE TABLE billing (
    bill_id VARCHAR(10) PRIMARY KEY,
    patient_id VARCHAR(10) NOT NULL,
    appointment_id VARCHAR(10) NOT NULL,
    bill_date DATE,
    amount DECIMAL(12,2),
    FOREIGN KEY (patient_id)
        REFERENCES patients(patient_id),
    FOREIGN KEY (appointment_id)
        REFERENCES appointments(appointment_id)
);

CREATE TABLE payments (
    payment_id VARCHAR(12) PRIMARY KEY,
    bill_id VARCHAR(10) NOT NULL,
    payment_date DATE,
    payment_method VARCHAR(30),
    amount_paid DECIMAL(12,2),
    payment_status VARCHAR(30),
    FOREIGN KEY (bill_id)
        REFERENCES billing(bill_id)
);
