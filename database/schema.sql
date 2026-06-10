CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('user', 'admin') NOT NULL DEFAULT 'user',
    auth_provider VARCHAR(40) NOT NULL DEFAULT 'local',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS family_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    household_name VARCHAR(120) NOT NULL,
    family_size INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS family_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    family_profile_id INT NOT NULL,
    display_name VARCHAR(120) NOT NULL,
    age INT NOT NULL,
    health_condition VARCHAR(120) DEFAULT NULL,
    weight_factor DECIMAL(5,2) DEFAULT 1.00,
    FOREIGN KEY (family_profile_id) REFERENCES family_profiles(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS receipts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    family_profile_id INT NULL,
    image_path VARCHAR(255) NOT NULL,
    extracted_text MEDIUMTEXT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (family_profile_id) REFERENCES family_profiles(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS food_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    standard_name VARCHAR(160) NOT NULL UNIQUE,
    category VARCHAR(100) NOT NULL
);

CREATE TABLE IF NOT EXISTS nutrition_data (
    id INT AUTO_INCREMENT PRIMARY KEY,
    food_item_id INT NOT NULL,
    sugar_g DECIMAL(8,2) DEFAULT 0,
    saturated_fat_g DECIMAL(8,2) DEFAULT 0,
    sodium_mg DECIMAL(8,2) DEFAULT 0,
    fiber_g DECIMAL(8,2) DEFAULT 0,
    nutrient_density DECIMAL(5,2) DEFAULT 0,
    FOREIGN KEY (food_item_id) REFERENCES food_items(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS receipt_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    receipt_id INT NOT NULL,
    raw_name VARCHAR(190) NOT NULL,
    normalized_name VARCHAR(190) NOT NULL,
    quantity DECIMAL(8,2) DEFAULT 1,
    food_item_id INT NULL,
    FOREIGN KEY (receipt_id) REFERENCES receipts(id) ON DELETE CASCADE,
    FOREIGN KEY (food_item_id) REFERENCES food_items(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS health_risks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    food_item_id INT NOT NULL,
    risk_type VARCHAR(120) NOT NULL,
    risk_description TEXT NOT NULL,
    recommendation TEXT NOT NULL,
    FOREIGN KEY (food_item_id) REFERENCES food_items(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS health_scores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    receipt_id INT NOT NULL,
    family_profile_id INT NULL,
    score DECIMAL(5,2) NOT NULL,
    score_label VARCHAR(60) NOT NULL,
    sugar_score DECIMAL(5,2) DEFAULT 0,
    fat_score DECIMAL(5,2) DEFAULT 0,
    sodium_score DECIMAL(5,2) DEFAULT 0,
    fiber_score DECIMAL(5,2) DEFAULT 0,
    diversity_score DECIMAL(5,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (receipt_id) REFERENCES receipts(id) ON DELETE CASCADE,
    FOREIGN KEY (family_profile_id) REFERENCES family_profiles(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS anomalies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    receipt_id INT NOT NULL,
    item_name VARCHAR(190) NOT NULL,
    metric_name VARCHAR(120) NOT NULL,
    value DECIMAL(10,2) NOT NULL,
    mean_value DECIMAL(10,2) NOT NULL,
    std_deviation DECIMAL(10,2) NOT NULL,
    z_score DECIMAL(8,2) NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (receipt_id) REFERENCES receipts(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS recommendations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    receipt_id INT NOT NULL,
    recommendation_text TEXT NOT NULL,
    explanation TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (receipt_id) REFERENCES receipts(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS trend_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    family_profile_id INT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    average_score DECIMAL(5,2) NOT NULL,
    sugar_total_g DECIMAL(10,2) DEFAULT 0,
    sodium_total_mg DECIMAL(10,2) DEFAULT 0,
    saturated_fat_total_g DECIMAL(10,2) DEFAULT 0,
    fiber_total_g DECIMAL(10,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (family_profile_id) REFERENCES family_profiles(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS medical_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    storage_key VARCHAR(160) NOT NULL,
    record_uid VARCHAR(80) NOT NULL,
    title VARCHAR(190) NULL,
    notes TEXT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_path VARCHAR(255) NOT NULL,
    file_type VARCHAR(20) NOT NULL,
    mime_type VARCHAR(120) NULL,
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX medical_records_user_id_index (user_id),
    INDEX medical_records_storage_key_index (storage_key),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);
