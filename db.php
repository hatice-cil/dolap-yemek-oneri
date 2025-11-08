<?php
// db.php — geliştirilmiş PDO bağlantısı ve yardımcı fonksiyonlar

// Ortam değişkenleri
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'dolap_db');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

function pdo()
{
    static $pdo = null;
    if ($pdo === null) {
        // PDO driver kontrolü
        if (!extension_loaded('pdo_mysql')) {
            die("PDO MySQL driver yüklü değil. Lütfen XAMPP'ı yeniden yükleyin veya php.ini'den pdo_mysql extension'ını etkinleştirin.");
        }

        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            die("Veritabanına bağlanılamadı: " . htmlspecialchars($e->getMessage()));
        }
    }
    return $pdo;
}

// Malzeme id getirir; yoksa oluşturur
function get_or_create_ingredient_id($name)
{
    $name = trim($name);
    if ($name === '')
        return null;
    $pdo = pdo();
    $stmt = $pdo->prepare("SELECT id FROM ingredients WHERE name = ?");
    $stmt->execute([$name]);
    $row = $stmt->fetch();
    if ($row)
        return (int) $row['id'];

    $stmt = $pdo->prepare("INSERT INTO ingredients (name) VALUES (?)");
    $stmt->execute([$name]);
    return (int) $pdo->lastInsertId();
}

function add_to_pantry($ingredient_name)
{
    $ingredient_id = get_or_create_ingredient_id($ingredient_name);
    if ($ingredient_id === null)
        return false;
    $pdo = pdo();
    // Aynı malzeme zaten varsa tekrar ekleme
    $exists = $pdo->prepare("SELECT 1 FROM pantry WHERE ingredient_id = ?");
    $exists->execute([$ingredient_id]);
    if ($exists->fetch())
        return true;

    $stmt = $pdo->prepare("INSERT INTO pantry (ingredient_id) VALUES (?)");
    return $stmt->execute([$ingredient_id]);
}

function delete_pantry_item($id)
{
    $pdo = pdo();
    $stmt = $pdo->prepare("DELETE FROM pantry WHERE id = ?");
    return $stmt->execute([(int) $id]);
}

function clear_pantry()
{
    $pdo = pdo();
    $stmt = $pdo->prepare("DELETE FROM pantry");
    $stmt->execute();
    return $stmt->rowCount();
}

// db.php dosyasında bu kısmı bulun:
function list_pantry()
{
    $pdo = pdo();
    // p.created_at yerine p.added_at kullanıldı
    $sql = "SELECT p.id, i.name, p.added_at
            FROM pantry p
            JOIN ingredients i ON i.id = p.ingredient_id
            ORDER BY i.name ASC";
    return $pdo->query($sql)->fetchAll();
}

function list_pantry_names()
{
    $rows = list_pantry();
    return array_map(fn($r) => $r['name'], $rows);
}

function get_all_ingredients()
{
    $pdo = pdo();
    $stmt = $pdo->prepare("
        SELECT name, 
               (SELECT COUNT(*) FROM pantry WHERE ingredient_id = ingredients.id) as count
        FROM ingredients 
        ORDER BY count DESC, name ASC
    ");
    $stmt->execute();
    return $stmt->fetchAll();
}

// DÜZELTİLMİŞ: Gelişmiş tarif arama (skorlu)
function search_recipes_scored($ingredients, $min_match = 1)
{
    if (empty($ingredients)) {
        return get_all_recipes();
    }

    $pdo = pdo();

    // Skor hesaplama için CASE ifadelerini hazırla
    $score_conditions = [];
    $params = [];

    foreach ($ingredients as $ingredient) {
        $score_conditions[] = "CASE WHEN r.ingredients_text LIKE ? THEN 1 ELSE 0 END";
        $params[] = '%' . $ingredient . '%';
    }

    $score_sql = implode(" + ", $score_conditions);

    $sql = "SELECT 
                r.*,
                ($score_sql) as score
            FROM recipes r
            HAVING score >= ?
            ORDER BY score DESC, r.title ASC
            LIMIT 100";

    $params[] = $min_match;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Basit tarif arama (yedek fonksiyon)
function search_recipes($names)
{
    if (!$names)
        return get_all_recipes();

    $pdo = pdo();

    $wheres = [];
    $params = [];
    foreach ($names as $n) {
        $wheres[] = "ingredients_text LIKE ?";
        $params[] = '%' . $n . '%';
    }

    $sql = "SELECT id, title, ingredients_text, instructions, prep_minutes, calories, tags, image_url
            FROM recipes
            WHERE " . implode(" AND ", $wheres) . "
            ORDER BY title ASC
            LIMIT 100";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Tüm tarifleri getir
function get_all_recipes()
{
    $pdo = pdo();
    $stmt = $pdo->prepare("
        SELECT id, title, ingredients_text, instructions, prep_minutes, calories, tags, image_url
        FROM recipes 
        ORDER BY title ASC
        LIMIT 100
    ");
    $stmt->execute();
    return $stmt->fetchAll();
}

// Recipe_ingredients tablosu için yardımcı fonksiyon
function get_recipe_ingredients($recipe_id)
{
    $pdo = pdo();
    $stmt = $pdo->prepare("
        SELECT i.name 
        FROM recipe_ingredients ri 
        JOIN ingredients i ON ri.ingredient_id = i.id 
        WHERE ri.recipe_id = ?
    ");
    $stmt->execute([$recipe_id]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Tarif ekleme fonksiyonu (ileride kullanım için)
function add_recipe($title, $ingredients_text, $instructions, $prep_minutes = null, $calories = null, $tags = null, $image_url = null)
{
    $pdo = pdo();

    $stmt = $pdo->prepare("
        INSERT INTO recipes (title, ingredients_text, instructions, prep_minutes, calories, tags, image_url) 
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $title,
        $ingredients_text,
        $instructions,
        $prep_minutes,
        $calories,
        $tags,
        $image_url
    ]);

    return $pdo->lastInsertId();
}

// Malzeme önerileri getir (otocomplete için)
function get_ingredient_suggestions($query)
{
    $pdo = pdo();
    $stmt = $pdo->prepare("
        SELECT name 
        FROM ingredients 
        WHERE name LIKE ? 
        ORDER BY 
            CASE WHEN name = ? THEN 1 
                 WHEN name LIKE ? THEN 2 
                 ELSE 3 
            END,
            name ASC
        LIMIT 10
    ");

    $searchTerm = $query . '%';
    $exactTerm = $query;
    $containsTerm = '%' . $query . '%';

    $stmt->execute([$searchTerm, $exactTerm, $containsTerm]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}
