-- Veritabanı oluştur
CREATE DATABASE IF NOT EXISTS dolap_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE dolap_db;

-- Malzemeler tablosu
CREATE TABLE IF NOT EXISTS ingredients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tarifler tablosu
CREATE TABLE IF NOT EXISTS recipes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    ingredients_text TEXT,
    instructions TEXT,
    prep_minutes INT,
    calories INT,
    tags VARCHAR(500),
    image_url VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tarif-malzeme ilişkisi
CREATE TABLE IF NOT EXISTS recipe_ingredients (
    recipe_id INT,
    ingredient_id INT,
    FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE,
    FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE,
    PRIMARY KEY (recipe_id, ingredient_id)
);

-- Dolap (kullanıcının malzemeleri)
CREATE TABLE IF NOT EXISTS pantry (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ingredient_id INT UNIQUE,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE
);

-- Örnek veriler
INSERT IGNORE INTO ingredients (name) VALUES 
('domates'), ('soğan'), ('sarımsak'), ('zeytinyağı'), ('tuz'), ('biber'),
('biber salçası'), ('domates salçası'), ('un'), ('şeker'), ('yumurta'),
('süt'), ('yoğurt'), ('limon'), ('maydanoz'), ('nane'), ('kıyma'),
('tavuk'), ('pirinç'), ('bulgur'), ('makarna'), ('patates'), ('havuç'),
('bezelye'), ('mısır'), ('peynir'), ('tereyağı'), ('sıvıyağ');

INSERT IGNORE INTO recipes (title, ingredients_text, instructions, prep_minutes, calories, tags) VALUES
('Domates Çorbası', 'domates, soğan, sarımsak, zeytinyağı, tuz, biber, un, tereyağı', '1. Soğan ve sarımsakları zeytinyağında kavurun\n2. Domatesleri ekleyip pişirin\n3. Un ve tereyağı ile kıvam verin\n4. Tuz ve biberle tatlandırın', 30, 180, 'çorba,vejetaryen'),
('Tavuk Sote', 'tavuk, soğan, sarımsak, biber, domates, zeytinyağı, tuz, biber', '1. Tavukları sotelen\n2. Sebzeleri ekleyin\n3. Kısık ateşte pişirin', 45, 320, 'ana yemek,tavuk'),
('Mercimek Çorbası', 'kırmızı mercimek, soğan, havuç, patates, zeytinyağı, tuz, kimyon, limon', '1. Mercimek ve sebzeleri haşlayın\n2. Blendırdan geçirin\n3. Baharatlarla tatlandırın', 40, 220, 'çorba,vejetaryen'),
('Makarna', 'makarna, tuz, su, zeytinyağı', '1. Suyu kaynatın\n2. Makarnayı ekleyin\n3. Süre kadar pişirin\n4. Süzün ve zeytinyağı ekleyin', 15, 280, 'pratik,makarna');

-- Tarif-malzeme ilişkilerini ekle
INSERT IGNORE INTO recipe_ingredients (recipe_id, ingredient_id)
SELECT r.id, i.id 
FROM recipes r, ingredients i 
WHERE (r.title = 'Domates Çorbası' AND i.name IN ('domates', 'soğan', 'sarımsak', 'zeytinyağı', 'tuz', 'biber', 'un', 'tereyağı'))
   OR (r.title = 'Tavuk Sote' AND i.name IN ('tavuk', 'soğan', 'sarımsak', 'biber', 'domates', 'zeytinyağı', 'tuz', 'biber'))
   OR (r.title = 'Mercimek Çorbası' AND i.name IN ('kırmızı mercimek', 'soğan', 'havuç', 'patates', 'zeytinyağı', 'tuz', 'kimyon', 'limon'))
   OR (r.title = 'Makarna' AND i.name IN ('makarna', 'tuz', 'su', 'zeytinyağı'));