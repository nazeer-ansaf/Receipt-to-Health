RECEIPT TO HEALTH
README - HOW TO RUN THE PROJECT

1. PROJECT OVERVIEW

Receipt to Health is a local PHP web application with a Python AI pipeline. It accepts receipt images or typed receipt text, extracts and normalizes food items, allows OCR correction, maps foods to nutrition and health-risk rules, calculates an explainable household health score, stores report history, and provides analytics, recommendations, and exports.

The application has three access modes: registered User, temporary Guest demo, and protected Administrator. The Administrator console is used for account management, food-catalog maintenance, machine-learning data and model management, external dataset review, holdout evaluation, and setup checks.

2. REQUIREMENTS

Use a Windows computer with the following installed:

- XAMPP with Apache and MySQL or MariaDB.
- PHP 8.0 or newer with PDO MySQL, fileinfo, JSON, mbstring, cURL, and OpenSSL enabled.
- Python 3.9 or newer. The project includes python\requirements.txt.
- Composer, only if the included vendor folder is missing or dependencies need to be restored.
- Tesseract OCR is recommended for image OCR. Typed text and TXT receipt analysis can be used without a local Tesseract installation.

3. PROJECT LOCATION

Copy or extract the project folder to:

C:\xampp\htdocs\receipt-to-health

The application URL is:

http://localhost/receipt-to-health/

Do not rename the project folder unless APP_URL and any related Apache configuration are updated.

4. DATABASE SETUP

1. Start Apache and MySQL from the XAMPP Control Panel.
2. Open http://localhost/phpmyadmin/.
3. Create a database named receipt_to_health using utf8mb4.
4. Select the new database and import database\schema.sql.
5. The default local database settings in includes\config.php are host 127.0.0.1, database receipt_to_health, user root, and an empty password. If the local MySQL password is different, update DB_HOST, DB_NAME, DB_USER, and DB_PASS in includes\config.php before running the application.

5. PHP AND APPLICATION CONFIGURATION

The project includes an optional .env.example file. If environment-specific values are required, copy it as .env and update the values without committing secrets:

APP_ENV=local
APP_URL=http://localhost/receipt-to-health
GOOGLE_CLIENT_ID=your-google-oauth-web-client-id.apps.googleusercontent.com
MAIL_FROM=no-reply@receipt-to-health.local

Google Login is optional. It appears only when a valid Google web client ID is configured. Password reset emails require local mail configuration; when mail is not configured in a local environment, the application can display a development reset link.

6. PYTHON ENVIRONMENT

If the included venv folder is available, open PowerShell in the project root and install or repair its packages:

venv\Scripts\python.exe -m pip install -r python\requirements.txt

If a virtual environment is not included, create one and install the packages:

py -m venv venv
venv\Scripts\activate
python -m pip install -r python\requirements.txt

The application first uses RECEIPT_TO_HEALTH_PYTHON from .env, then venv\Scripts\python.exe when it exists, and finally the system python command. If Python is installed elsewhere, set the full path in .env, for example:

RECEIPT_TO_HEALTH_PYTHON=C:\path\to\receipt-to-health\venv\Scripts\python.exe

For image OCR, install Tesseract separately and add its installation directory to PATH. A common Windows installation path is C:\Program Files\Tesseract-OCR.

7. COMPOSER DEPENDENCIES

The repository normally contains vendor\ and composer.lock. If vendor\ is missing, run the following from the project root:

composer install --no-dev

The main Composer dependency is google/apiclient, which is required only for the optional Google authentication flow.

8. REQUIRED WRITABLE DIRECTORIES

The application writes uploaded receipts, OCR drafts, reports, medical records, and external-import workspaces to the following directories. Confirm that Apache/PHP can write to them:

uploads\
data\results\
data\ocr_drafts\
data\medical_records\
data\external_imports\
data\external_datasets\
data\external_jobs\

The folders already contain .gitkeep files in the project. Do not delete the .htaccess protection file under data\medical_records.

9. MACHINE-LEARNING MODEL READINESS

If the trained model files are already present under python\models\, no training command is required for normal use. If the model is missing, train it from the project root:

venv\Scripts\python.exe python\train_food_model.py

If venv is not being used, replace the first part of the command with python. The training dataset is data\training_food_items.csv. The training command creates or updates:

python\models\food_classifier.joblib
python\models\food_classifier_metrics.json
python\models\food_classifier_model.json

The optional controlled-variant workflow is:

venv\Scripts\python.exe python\generate_training_variants.py
venv\Scripts\python.exe python\train_food_model.py

Do not use real holdout data for training. The file data\real_receipt_holdout.csv is reserved for evaluation-only evidence.

10. START THE APPLICATION

1. Start Apache and MySQL in XAMPP.
2. Confirm the database is available.
3. Open http://localhost/receipt-to-health/.
4. Choose Register to create a normal user, Login for an existing user, or Continue as guest for a temporary demonstration.
5. Complete Health Profile before running a personalized report.

The main workflow is:

Health Profile -> Upload or paste receipt -> OCR Review -> Correct items -> Analyze -> Dashboard -> History or Analytics -> Print or export report.

11. VERIFY THE INSTALLATION

Open the setup checker:

http://localhost/receipt-to-health/setup_check.php

The page checks PHP version, writable folders, Python, the sample AI pipeline, the training dataset, the model, model metrics, and the MySQL connection. All checks should show OK before a demonstration or submission.

The standalone Python verification can also be run from the project root:

venv\Scripts\python.exe python\verify_pipeline.py

The sample receipts available for testing are:

samples\demo_receipt.txt
samples\final_year_demo_receipt.txt
samples\holdout_demo.csv

The holdout demo CSV is a UI fixture only. It must not be described as genuine real-world evaluation evidence.

12. FIRST USER TEST

1. Register a user and save a health profile.
2. Add household size, age group, conditions, allergies, restrictions, and notes where appropriate.
3. Open Upload Receipt and select samples\demo_receipt.txt, or paste equivalent receipt lines.
4. Keep Review detected items enabled.
5. In OCR Review, remove non-food lines, correct names and quantities, and analyze the corrected basket.
6. Read the Dashboard score, alerts, OCR confidence, score breakdown, family context, recommendations, and coverage warning.
7. Open History, Analytics, and Reports. Test Print report and the PDF, JSON, and CSV export actions.

For a quick demonstration, use the visible Try instant demo or Try item correction controls on the Upload Receipt page after entering Guest mode.

13. INITIAL ADMINISTRATOR SETUP

Public registration intentionally creates only normal User accounts. After registering the first local account, an operator can promote that account for a controlled local demonstration through phpMyAdmin:

UPDATE users SET role = 'admin' WHERE email = 'your-email@example.com';

Replace the email with the account that was just registered. Sign out and sign in again so the new role is loaded. After an Administrator exists, use admin.php to create additional User or Administrator accounts. Do not place admin passwords in this file or in the submitted artefact.

Open the Administrator console at:

http://localhost/receipt-to-health/admin.php

The Admin Console includes User Accounts, Food Catalog Records, ML Dataset and Model, Training Candidate Review, External Dataset Import, Real-world Holdout Evaluation, and data-integrity summaries. User corrections remain quarantined in data\training_candidates.csv until an Administrator approves or rejects them. External imports are staged and reviewed; importing or inspecting an external dataset does not automatically retrain the model. Holdout data is evaluation-only and must remain separate from training data.

14. COMMON PROBLEMS

Blank page or HTTP 500: check the Apache PHP error log, confirm PHP 8+, confirm the database settings in includes\config.php, and confirm vendor\ exists when Google authentication is enabled.

Database connection error: start MySQL, confirm that the receipt_to_health database exists, import database\schema.sql, and check the DB_HOST, DB_NAME, DB_USER, and DB_PASS values.

Python not found: run the Python command from the project root, confirm venv\Scripts\python.exe exists, or set RECEIPT_TO_HEALTH_PYTHON to an absolute Python path in .env.

Model not loaded: install python\requirements.txt and run python\train_food_model.py. Confirm that python\models\food_classifier.joblib, food_classifier_metrics.json, and food_classifier_model.json exist.

Image OCR is empty or inaccurate: use a flat, clear, well-lit receipt; install Tesseract; or use Paste Text/TXT input and correct items in OCR Review.

Upload or report save failure: confirm that uploads\, data\results\, and data\ocr_drafts\ are writable and that the selected file is within the 10 MB limit.

Medical-record failure: use PDF, JPG, JPEG, PNG, WEBP, or TXT up to 10 MB and confirm data\medical_records\ is writable. Treat medical records as sensitive data.

Search warning: confirm that the current search.php is deployed. The nested food-catalog Array-to-string warning has been fixed by safely serializing complete catalogue records before searching.

15. SECURITY AND DEMONSTRATION NOTES

This project is configured for local development and academic demonstration. Use strong local passwords, do not expose .env, do not publish real medical records, and anonymize screenshots and receipt data used in the thesis or viva. Logout from shared computers. Before public deployment, configure production database credentials, HTTPS, mail delivery, file access rules, and server permissions.

16. ARTEFACT SUBMISSION NOTE

Place this README.txt in the required folder named Reg Number-Artefact together with the source code, User Manual, A1 poster, optional test videos, and Assignment 2 topsheet. Remove outdated or half-finished files before zipping. If the final artefact exceeds the 600 MB Breo limit, upload the project to a shareable platform, set the link to Anyone with the link can view, and add that link to this section before submission.

END OF README
