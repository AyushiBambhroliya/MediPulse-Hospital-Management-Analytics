
import csv
import os
import mysql.connector

from mysql.connector import Error

# Project folder
BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# Import order is important because of foreign keys
FILES = [
    ("departments", "departments.csv",
     ["department_id", "department_name", "location"]),

    ("doctors", "doctors.csv",
     ["doctor_id", "doctor_name", "department_id",
      "specialization", "experience_years"]),

    ("patients", "patients.csv",
     ["patient_id", "patient_name", "age", "gender",
      "city", "registration_date"]),

    ("appointments", "appointments.csv",
     ["appointment_id", "patient_id", "doctor_id",
      "appointment_date", "status", "waiting_time_minutes"]),

    ("billing", "billing.csv",
     ["bill_id", "patient_id", "appointment_id",
      "bill_date", "amount"]),

    ("payments", "payments.csv",
     ["payment_id", "bill_id", "payment_date",
      "payment_method", "amount_paid", "payment_status"])
]


def clean_value(value):
    value = value.strip()
    return value if value else None


def import_data():
    connection = None
    cursor = None

    try:
        connection = mysql.connector.connect(
            host="localhost",
            user="root",
            password="",
            database="medipulse"
        )

        cursor = connection.cursor()

        # Prevent accidental duplicate imports
        for table, filename, columns in FILES:
            cursor.execute(f"SELECT COUNT(*) FROM `{table}`")
            count = cursor.fetchone()[0]

            if count > 0:
                raise Exception(
                    f"Table '{table}' already contains {count} records. "
                    "Import stopped to prevent duplicates."
                )

        # Import all six CSV files
        for table, filename, columns in FILES:

            file_path = os.path.join(BASE_DIR, filename)

            if not os.path.exists(file_path):
                raise FileNotFoundError(
                    f"CSV file not found: {file_path}"
                )

            print(f"\nImporting {filename}...")

            placeholders = ", ".join(["%s"] * len(columns))
            column_names = ", ".join(
                f"`{column}`" for column in columns
            )

            query = (
                f"INSERT INTO `{table}` ({column_names}) "
                f"VALUES ({placeholders})"
            )

            rows = []

            with open(
                file_path,
                "r",
                encoding="utf-8-sig",
                newline=""
            ) as file:

                reader = csv.DictReader(file)

                if reader.fieldnames != columns:
                    raise Exception(
                        f"Column mismatch in {filename}. "
                        f"Expected: {columns}; "
                        f"Found: {reader.fieldnames}"
                    )

                for row in reader:
                    values = tuple(
                        clean_value(row[column])
                        for column in columns
                    )
                    rows.append(values)

            if rows:
                cursor.executemany(query, rows)

            print(f"Successfully prepared {len(rows)} records.")

        # Save all six imports together
        connection.commit()

        print("\n================================")
        print("MEDIPULSE DATA IMPORT SUCCESSFUL")
        print("================================")

        for table, filename, columns in FILES:
            cursor.execute(f"SELECT COUNT(*) FROM `{table}`")
            print(f"{table}: {cursor.fetchone()[0]} records")

        print("\nAll datasets imported successfully!")

    except Exception as error:
        if connection and connection.is_connected():
            connection.rollback()

        print("\nIMPORT FAILED!")
        print("Reason:", error)
        print("Changes have been rolled back.")

    finally:
        if cursor:
            cursor.close()

        if connection and connection.is_connected():
            connection.close()


if __name__ == "__main__":
    import_data()
