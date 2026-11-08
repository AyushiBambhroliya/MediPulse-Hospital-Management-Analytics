
import mysql.connector

def get_connection():
    try:
        connection = mysql.connector.connect(
            host="localhost",
            user="root",
            password="",
            database="medipulse"
        )

        if connection.is_connected():
            print("MediPulse database connected successfully!")

        return connection

    except mysql.connector.Error as error:
        print("Database connection failed:", error)
        return None


if __name__ == "__main__":
    db = get_connection()

    if db:
        db.close()
        print("Connection closed.")
