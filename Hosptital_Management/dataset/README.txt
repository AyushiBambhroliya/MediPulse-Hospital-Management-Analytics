HOSPITAL MANAGEMENT ANALYTICS - SYNTHETIC DATASET V2

This is fictional learning data; it contains no real patient records.

Files:
patients.csv (1,000 rows)
doctors.csv (30 rows)
departments.csv (10 rows)
appointments.csv (3,000 rows)
billing.csv (2,500 rows)
payments.csv (2,500 rows)

Relationships:
doctors.department_id -> departments.department_id
appointments.patient_id -> patients.patient_id
appointments.doctor_id -> doctors.doctor_id
billing.patient_id -> patients.patient_id
billing.appointment_id -> appointments.appointment_id
payments.bill_id -> billing.bill_id

Appointment statuses: Completed, Cancelled, Pending, No-show.
Billing rows are only for completed appointments.
Payment statuses: Paid, Partial, Pending.
Outstanding balance = billing.amount - payments.amount_paid.
Use payments.amount_paid for collected revenue; billing.amount is billed revenue.
Dates use YYYY-MM-DD. Treat IDs as text.
