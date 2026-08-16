# Receipt-to-Health FYP Diagrams

This file contains Mermaid diagrams for the project documentation. You can paste each diagram into a Mermaid-supported editor, Markdown preview, or documentation tool.

## 1. Use Case Diagram

```mermaid
flowchart LR
    Guest["Guest User"]
    User["Registered User"]
    Admin["Admin"]

    subgraph System["Receipt-to-Health System"]
        UC1["Register / Login"]
        UC2["Continue as Guest"]
        UC3["Set Up Health Profile"]
        UC4["Upload Receipt"]
        UC5["Review / Correct OCR Items"]
        UC6["Analyze Receipt"]
        UC7["View Dashboard"]
        UC8["View History and Trends"]
        UC9["Export Report"]
        UC10["Search Foods / Reports"]
        UC11["Upload Medical Records"]
        UC12["Manage Users"]
        UC13["Manage Food Catalog"]
        UC14["Review Admin Reports"]
        UC15["Run System Check"]
    end

    Guest --> UC2
    Guest --> UC3
    Guest --> UC4
    Guest --> UC5
    Guest --> UC6
    Guest --> UC7
    Guest --> UC9

    User --> UC1
    User --> UC3
    User --> UC4
    User --> UC5
    User --> UC6
    User --> UC7
    User --> UC8
    User --> UC9
    User --> UC10
    User --> UC11

    Admin --> UC1
    Admin --> UC12
    Admin --> UC13
    Admin --> UC14
    Admin --> UC15
    Admin --> UC9
```

## 2. System Architecture Diagram

```mermaid
flowchart TB
    Browser["Web Browser"]

    subgraph PHPApp["PHP Application on Apache / XAMPP"]
        Pages["PHP Pages\nLogin, Profile, Upload, Dashboard, Reports, Admin"]
        APIs["API Endpoints\nprocess_receipt, analyze_items, export_report, upload_medical_record"]
        Includes["Shared Includes\nAuth, Profile, Analysis, Results, Catalog, Medical Records"]
    end

    subgraph AI["Python Analysis Layer"]
        OCR["OCR Extraction\nTesseract / EasyOCR path"]
        NLP["Receipt Item Parsing"]
        ML["ML Food Classifier\nItem and category prediction"]
        Scoring["Nutrition Scoring and Risk Logic"]
        Recommendations["Recommendation Generation"]
    end

    subgraph Storage["Storage Layer"]
        MySQL["MySQL Database\nusers, receipts, scores, items, recommendations"]
        JSON["JSON Files\ndata/results, profiles, OCR drafts"]
        Uploads["Uploaded Files\nreceipts and medical records"]
        Catalog["Food Catalog JSON\ndata/food_catalog.json"]
    end

    Browser --> Pages
    Browser --> APIs
    Pages --> Includes
    APIs --> Includes
    APIs --> AI
    AI --> Catalog
    NLP --> ML
    ML --> Catalog
    AI --> JSON
    Includes --> MySQL
    Includes --> JSON
    Includes --> Uploads
    Pages --> JSON
    Pages --> MySQL
```

## 3. Data Flow Diagram Level 0

```mermaid
flowchart LR
    User["User / Guest / Admin"]
    System["Receipt-to-Health System"]
    DB["Database and File Storage"]
    Report["Health Report / Dashboard"]

    User -->|"Profile details, receipt, health notes"| System
    System -->|"Stores users, receipts, profiles, results"| DB
    DB -->|"Food rules, history, profile context"| System
    System -->|"Score, risks, recommendations, exports"| Report
    Report -->|"Dashboard, PDF, CSV, JSON"| User
```

## 4. Data Flow Diagram Level 1

```mermaid
flowchart TB
    User["User"]
    Receipt["Receipt Image / Text"]
    Profile["Health Profile and Medical Context"]
    OCR["1. OCR / Text Extraction"]
    Normalize["2. Item Normalization"]
    Match["3. Food Catalog Matching"]
    Nutrition["4. Nutrition Calculation"]
    Score["5. Personalized Health Scoring"]
    Anomaly["6. Anomaly Detection"]
    Advice["7. Recommendation Engine"]
    Store["8. Store Result"]
    Dashboard["9. Dashboard / Report"]

    FoodCatalog["Food Catalog JSON"]
    Results["JSON Result Files"]
    Database["MySQL Tables"]

    User --> Receipt
    User --> Profile
    Receipt --> OCR
    OCR --> Normalize
    Normalize --> Match
    Normalize --> MLClassifier["ML Item / Category Classifier"]
    MLClassifier --> Match
    FoodCatalog --> Match
    Match --> Nutrition
    Profile --> Score
    Nutrition --> Score
    Score --> Anomaly
    Score --> Advice
    Anomaly --> Store
    Advice --> Store
    Store --> Results
    Store --> Database
    Results --> Dashboard
    Database --> Dashboard
    Dashboard --> User
```

## 5. ER Diagram / Database Schema

```mermaid
erDiagram
    USERS ||--o{ FAMILY_PROFILES : owns
    USERS ||--o{ RECEIPTS : uploads
    USERS ||--o{ MEDICAL_RECORDS : uploads

    FAMILY_PROFILES ||--o{ FAMILY_MEMBERS : contains
    FAMILY_PROFILES ||--o{ RECEIPTS : links
    FAMILY_PROFILES ||--o{ HEALTH_SCORES : receives
    FAMILY_PROFILES ||--o{ TREND_HISTORY : summarizes

    RECEIPTS ||--o{ RECEIPT_ITEMS : contains
    RECEIPTS ||--o{ HEALTH_SCORES : generates
    RECEIPTS ||--o{ ANOMALIES : produces
    RECEIPTS ||--o{ RECOMMENDATIONS : produces

    FOOD_ITEMS ||--o{ NUTRITION_DATA : has
    FOOD_ITEMS ||--o{ RECEIPT_ITEMS : matches
    FOOD_ITEMS ||--o{ HEALTH_RISKS : defines

    USERS {
        int id PK
        string name
        string email
        string password_hash
        enum role
        string auth_provider
        timestamp created_at
    }

    FAMILY_PROFILES {
        int id PK
        int user_id FK
        string household_name
        int family_size
        timestamp created_at
    }

    FAMILY_MEMBERS {
        int id PK
        int family_profile_id FK
        string display_name
        int age
        string health_condition
        decimal weight_factor
    }

    RECEIPTS {
        int id PK
        int user_id FK
        int family_profile_id FK
        string image_path
        text extracted_text
        timestamp uploaded_at
    }

    FOOD_ITEMS {
        int id PK
        string standard_name
        string category
    }

    NUTRITION_DATA {
        int id PK
        int food_item_id FK
        decimal sugar_g
        decimal saturated_fat_g
        decimal sodium_mg
        decimal fiber_g
        decimal nutrient_density
    }

    RECEIPT_ITEMS {
        int id PK
        int receipt_id FK
        string raw_name
        string normalized_name
        decimal quantity
        int food_item_id FK
    }

    HEALTH_RISKS {
        int id PK
        int food_item_id FK
        string risk_type
        text risk_description
        text recommendation
    }

    HEALTH_SCORES {
        int id PK
        int receipt_id FK
        int family_profile_id FK
        decimal score
        string score_label
        decimal sugar_score
        decimal fat_score
        decimal sodium_score
        decimal fiber_score
        decimal diversity_score
    }

    ANOMALIES {
        int id PK
        int receipt_id FK
        string item_name
        string metric_name
        decimal value
        decimal z_score
        text message
    }

    RECOMMENDATIONS {
        int id PK
        int receipt_id FK
        text recommendation_text
        text explanation
    }

    TREND_HISTORY {
        int id PK
        int family_profile_id FK
        date period_start
        date period_end
        decimal average_score
    }

    MEDICAL_RECORDS {
        int id PK
        int user_id FK
        string storage_key
        string record_uid
        string original_name
        string stored_path
        string file_type
        bigint file_size
    }
```

## 6. Activity Diagram - Receipt Analysis Flow

```mermaid
flowchart TD
    Start([Start])
    Login{Logged in or guest mode?}
    Access["Login / Register / Continue as Guest"]
    Profile["Enter family size, age group, conditions, health notes"]
    Upload["Upload receipt image or text file"]
    Validate{"Allowed file type?"}
    SaveFile["Save uploaded receipt"]
    Analyze["Run Python OCR and analysis"]
    Review{Review detected items?}
    Draft["Save OCR draft"]
    Correct["User corrects item names and quantities"]
    Reanalyze["Analyze corrected items"]
    Persist["Persist result to JSON and database"]
    Dashboard["Display dashboard with score, alerts, recommendations"]
    Export{Export needed?}
    ExportReport["Generate PDF / CSV / JSON"]
    End([End])

    Start --> Login
    Login -- No --> Access --> Profile
    Login -- Yes --> Profile
    Profile --> Upload
    Upload --> Validate
    Validate -- No --> Upload
    Validate -- Yes --> SaveFile
    SaveFile --> Analyze
    Analyze --> Review
    Review -- Yes --> Draft --> Correct --> Reanalyze --> Persist
    Review -- No --> Persist
    Persist --> Dashboard
    Dashboard --> Export
    Export -- Yes --> ExportReport --> End
    Export -- No --> End
```

## 7. Sequence Diagram - Receipt Processing

```mermaid
sequenceDiagram
    actor User
    participant Browser
    participant PHP as PHP Upload/API
    participant Profile as Profile Service
    participant Python as Python Analysis Script
    participant Catalog as Food Catalog
    participant DB as MySQL Database
    participant JSON as JSON Result Store
    participant Dashboard

    User->>Browser: Choose receipt and enter health notes
    Browser->>PHP: POST /api/process_receipt.php
    PHP->>Profile: Load and update health profile
    PHP->>PHP: Validate file and save upload
    PHP->>Python: Run receipt analysis command
    Python->>Catalog: Match items against food rules
    Catalog-->>Python: Nutrients, risk labels, swaps
    Python-->>PHP: Analysis result JSON
    PHP->>DB: Persist receipt, items, score, anomalies, recommendations
    PHP->>JSON: Save result file and latest result
    PHP-->>Browser: Redirect to dashboard
    Browser->>Dashboard: GET /dashboard.php?id=result_id
    Dashboard->>JSON: Load result
    Dashboard-->>User: Show score, alerts, proof, export actions
```

## 8. Admin Workflow Diagram

```mermaid
flowchart TD
    Start([Admin starts])
    Login["Admin login"]
    CheckRole{"Role is admin?"}
    Denied["Show admin access required"]
    Panel["Open Admin Control Panel"]
    Users["Manage User Accounts"]
    Catalog["Manage Food Catalog"]
    Reports["Review Generated Reports"]
    System["Run System Check"]
    CreateUser["Create user or admin account"]
    EditFood["Add / edit / delete food rule"]
    CSV["Import / export catalog CSV"]
    OpenReport["Open report dashboard or PDF"]
    End([End])

    Start --> Login --> CheckRole
    CheckRole -- No --> Denied --> End
    CheckRole -- Yes --> Panel
    Panel --> Users --> CreateUser --> Panel
    Panel --> Catalog --> EditFood --> Panel
    Catalog --> CSV --> Panel
    Panel --> Reports --> OpenReport --> Panel
    Panel --> System --> End
```

## 9. AI / Processing Pipeline Diagram

```mermaid
flowchart LR
    A["Receipt Input\nImage or Text"]
    B["OCR Extraction\nText from image"]
    C["NLP Parsing\nDetect food lines"]
    D["Normalization\nClean item names and quantities"]
    E["Knowledge Matching\nFood catalog aliases and categories"]
    M["ML Classification\nPredict item/category when alias match fails"]
    F["Nutrition Calculation\nSugar, sodium, saturated fat, fiber"]
    G["Personalized Scoring\nFamily size, age group, conditions"]
    H["Anomaly Detection\nUnusual or high-risk purchases"]
    I["Recommendation Engine\nActions and healthier swaps"]
    J["Explainable Output\nDashboard, proof, report export"]

    A --> B --> C --> D --> E
    D --> M --> E
    E --> F --> G --> H --> I --> J
```

## 10. Deployment Diagram

```mermaid
flowchart TB
    subgraph Client["Client Machine"]
        Browser["Web Browser"]
    end

    subgraph Server["Local XAMPP Server"]
        Apache["Apache Web Server"]
        PHP["PHP Runtime"]
        Python["Python Runtime\nprocess_receipt.py"]
        MySQL["MySQL Server\nreceipt_to_health"]
        FileSystem["Local File System\nuploads, data/results, profiles, medical_records"]
    end

    Browser -->|"HTTP requests"| Apache
    Apache --> PHP
    PHP -->|"executes analysis"| Python
    PHP -->|"SQL queries"| MySQL
    PHP -->|"read/write files"| FileSystem
    Python -->|"read receipt / catalog"| FileSystem
    Python -->|"analysis JSON"| PHP
```

## 11. Navigation / Site Map Diagram

```mermaid
flowchart TD
    Login["Login"]
    Register["Register"]
    Profile["Health Profile"]
    Upload["Analyze Receipt"]
    OCR["Fix Items / OCR Review"]
    Dashboard["Results Dashboard"]
    Analytics["Trends / Analytics"]
    History["Receipt History"]
    Reports["Readable Report"]
    Foods["Food Lookup"]
    Simulator["Simulator"]
    Graph["Knowledge Graph"]
    Family["Family"]
    Account["Account"]
    Search["Search"]
    Method["Methodology"]
    Admin["Admin Panel"]
    Setup["System Check"]

    Login --> Profile
    Register --> Profile
    Profile --> Upload
    Upload --> OCR
    Upload --> Dashboard
    OCR --> Dashboard
    Dashboard --> Reports
    Dashboard --> Analytics
    Dashboard --> History
    Analytics --> Reports
    History --> Dashboard
    Foods --> Search
    Search --> Dashboard
    Search --> Foods
    Profile --> Family
    Profile --> Account
    Upload --> Simulator
    Foods --> Graph
    Account --> Method
    Admin --> Setup
    Admin --> Reports
    Admin --> Foods
```

## 12. Report Generation Flow Diagram

```mermaid
flowchart TD
    Start([Start])
    Select["User opens Reports page or export endpoint"]
    Load{"Result exists?"}
    Empty["Show no report available message"]
    Result["Load latest or selected result"]
    Format{"Selected format"}
    Page["Render printable report page"]
    PDF["Generate PDF export"]
    CSV["Generate CSV export"]
    JSON["Generate JSON export"]
    Download["Download / Print / Share"]
    End([End])

    Start --> Select --> Load
    Load -- No --> Empty --> End
    Load -- Yes --> Result --> Format
    Format -- Web page --> Page --> Download
    Format -- PDF --> PDF --> Download
    Format -- CSV --> CSV --> Download
    Format -- JSON --> JSON --> Download
    Download --> End
```
