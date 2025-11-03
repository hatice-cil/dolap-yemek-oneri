<?php
require_once __DIR__ . '/db.php';

/* -----------------------------------------------------------
 * Yardımcılar
 * ---------------------------------------------------------*/
if (!function_exists('norm')) {
    function norm($s)
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $tr = ['ı' => 'i', 'İ' => 'i', 'ç' => 'c', 'ğ' => 'g', 'ö' => 'o', 'ş' => 's', 'ü' => 'u'];
        return strtr($s, $tr);
    }
}

if (!function_exists('parse_ingredients_text')) {
    function parse_ingredients_text($text)
    {
        $text = norm($text);
        $parts = preg_split('/[,;|\n]+/u', $text);
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') $out[] = $p;
        }
        return array_values(array_unique($out));
    }
}

/* -----------------------------------------------------------
 * CSRF koruması
 * ---------------------------------------------------------*/
session_start();
if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

/* -----------------------------------------------------------
 * POST işlemleri
 * ---------------------------------------------------------*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf']) || $_POST['csrf'] !== $_SESSION['csrf']) {
        http_response_code(400);
        echo "Geçersiz istek (CSRF).";
        exit;
    }
    
    if (isset($_POST['ingredient']) && $_POST['ingredient'] !== '') {
        add_to_pantry($_POST['ingredient']);
    }
    
    if (isset($_POST['delete_id'])) {
        delete_pantry_item($_POST['delete_id']);
    }
    
    if (isset($_POST['clear_all'])) {
        clear_pantry();
    }
    
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

/* -----------------------------------------------------------
 * GET parametreleri ile malzeme seçimi
 * ---------------------------------------------------------*/
$selected_ingredients = [];
if (isset($_GET['ingredients']) && is_array($_GET['ingredients'])) {
    $selected_ingredients = array_map('trim', $_GET['ingredients']);
    $selected_ingredients = array_filter($selected_ingredients);
}

/* -----------------------------------------------------------
 * Veri hazırlanışı
 * ---------------------------------------------------------*/
$pantry = list_pantry();
$pantry_names = list_pantry_names();
$pantry_norm = array_map('norm', $pantry_names);

// Seçili malzemeler veya tüm dolap
$search_ingredients = !empty($selected_ingredients) ? $selected_ingredients : $pantry_names;

$min_match = isset($_GET['min_match']) ? max(1, (int)$_GET['min_match']) : 1;
$q = isset($_GET['q']) ? trim($_GET['q']) : '';

/* Tarif arama */
if (function_exists('search_recipes_scored')) {
    $recipes = search_recipes_scored($search_ingredients, $min_match);
} else {
    $recipes = search_recipes($search_ingredients);
    foreach ($recipes as &$rr) {
        $score = 0;
        foreach ($search_ingredients as $n) {
            if (stripos($rr['ingredients_text'], $n) !== false) $score++;
        }
        $rr['score'] = $score;
    }
    unset($rr);
    
    $recipes = array_values(array_filter($recipes, fn($r) => (int)$r['score'] >= $min_match));
    
    usort($recipes, function ($a, $b) {
        if ($a['score'] === $b['score']) return strcasecmp($a['title'], $b['title']);
        return $b['score'] <=> $a['score'];
    });
}

/* Metin araması */
if ($q !== '') {
    $qLower = mb_strtolower($q, 'UTF-8');
    $recipes = array_values(array_filter($recipes, function ($r) use ($qLower) {
        return (mb_strpos(mb_strtolower($r['title'], 'UTF-8'), $qLower) !== false) ||
            (mb_strpos(mb_strtolower($r['ingredients_text'], 'UTF-8'), $qLower) !== false);
    }));
}

// Tüm mevcut malzemeler (tıklanabilir liste için)
$all_ingredients = get_all_ingredients();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Akıllı Yemek Öneri Sistemi</title>
    <link rel="stylesheet" href="styles.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <header class="header">
            <div class="logo">
                <i class="fas fa-utensils"></i>
                <h1>Akıllı Yemek Öneri Sistemi</h1>
            </div>
            <p class="tagline">Dolabındaki malzemelerle harika yemekler keşfet!</p>
        </header>

        <div class="main-layout">
            <!-- Sol Sidebar - Malzeme Yönetimi -->
            <aside class="sidebar">
                <section class="card">
                    <h2><i class="fas fa-plus-circle"></i> Malzeme Ekle</h2>
                    <form method="post" class="add-form">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>" />
                        <div class="input-group">
                            <input type="text" name="ingredient" placeholder="örn. domates, soğan, zeytinyağı..." required />
                            <button type="submit" class="btn-primary">
                                <i class="fas fa-plus"></i> Ekle
                            </button>
                        </div>
                    </form>
                </section>

                <section class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-refresh"></i> Malzeme Seçimi</h2>
                        <div class="card-actions">
                            <a href="?" class="btn-sm">Sıfırla</a>
                        </div>
                    </div>
                    <p class="help-text">Tarif aramak için malzemeleri seçin:</p>
                    
                    <form method="get" class="ingredient-selector" id="ingredientForm">
                        <input type="hidden" name="min_match" value="<?= (int)$min_match ?>" />
                        <input type="hidden" name="q" value="<?= htmlspecialchars($q) ?>" />
                        
                        <div class="ingredient-grid">
                            <?php foreach ($all_ingredients as $ingredient): ?>
                                <?php $isSelected = in_array($ingredient['name'], $selected_ingredients); ?>
                                <label class="ingredient-checkbox <?= $isSelected ? 'selected' : '' ?>">
                                    <input type="checkbox" name="ingredients[]" value="<?= htmlspecialchars($ingredient['name']) ?>" 
                                           <?= $isSelected ? 'checked' : '' ?> onchange="document.getElementById('ingredientForm').submit()" />
                                    <span class="checkmark"></span>
                                    <?= htmlspecialchars($ingredient['name']) ?>
                                    <span class="ingredient-count"><?= $ingredient['count'] ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </form>
                </section>

                <section class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-clipboard-list"></i> Dolabım</h2>
                        <?php if ($pantry): ?>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>" />
                                <input type="hidden" name="clear_all" value="1" />
                                <button type="submit" class="btn-sm btn-danger" onclick="return confirm('Tüm malzemeleri silmek istediğinizden emin misiniz?')">
                                    <i class="fas fa-trash"></i> Temizle
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($pantry): ?>
                        <div class="pantry-list">
                            <?php foreach ($pantry as $p): ?>
                                <div class="pantry-item">
                                    <span class="pantry-name"><?= htmlspecialchars($p['name']) ?></span>
                                    <form method="post" class="inline-form">
                                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>" />
                                        <input type="hidden" name="delete_id" value="<?= (int)$p['id'] ?>" />
                                        <button type="submit" class="btn-icon" title="Sil">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <p>Henüz malzeme eklemediniz.</p>
                        </div>
                    <?php endif; ?>
                </section>
            </aside>

            <!-- Ana İçerik - Tarifler -->
            <main class="content">
                <section class="card">
                    <div class="filters">
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" id="searchInput" placeholder="Tarif ara..." value="<?= htmlspecialchars($q) ?>" 
                                   onkeypress="if(event.keyCode==13) document.getElementById('searchForm').submit()" />
                        </div>
                        
                        <form method="get" id="searchForm" class="filter-form">
                            <?php if (!empty($selected_ingredients)): ?>
                                <?php foreach ($selected_ingredients as $ing): ?>
                                    <input type="hidden" name="ingredients[]" value="<?= htmlspecialchars($ing) ?>" />
                                <?php endforeach; ?>
                            <?php endif; ?>
                            
                            <div class="filter-group">
                                <label for="min_match" class="filter-label">
                                    <i class="fas fa-filter"></i> Min Eşleşme:
                                </label>
                                <select name="min_match" id="min_match" onchange="document.getElementById('searchForm').submit()">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <option value="<?= $i ?>" <?= $min_match == $i ? 'selected' : '' ?>><?= $i ?></option>
                                    <?php endfor; ?>
                                </select>
                                <button type="submit" class="btn-secondary">
                                    <i class="fas fa-check"></i> Uygula
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="results-header">
                        <h2>
                            <i class="fas fa-list-alt"></i> 
                            Önerilen Tarifler 
                            <span class="results-count">(<?= count($recipes) ?> bulundu)</span>
                        </h2>
                        
                        <?php if (!empty($selected_ingredients)): ?>
                            <div class="selected-indicator">
                                <strong>Seçili Malzemeler:</strong>
                                <?php foreach ($selected_ingredients as $ing): ?>
                                    <span class="selected-tag"><?= htmlspecialchars($ing) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($recipes): ?>
                        <div class="recipes-grid">
                            <?php foreach ($recipes as $r): ?>
                                <?php
                                $ing_list = parse_ingredients_text($r['ingredients_text']);
                                $ing_norm = $ing_list;
                                $have = array_values(array_intersect($ing_norm, $pantry_norm));
                                $missing = array_values(array_diff($ing_norm, $pantry_norm));
                                $score = isset($r['score']) ? (int)$r['score'] : count($have);
                                $match_percentage = count($ing_list) > 0 ? round(($score / count($ing_list)) * 100) : 0;
                                $is_complete = count($missing) === 0;
                                ?>
                                
                                <div class="recipe-card <?= $is_complete ? 'complete' : '' ?>">
                                    <div class="recipe-header">
                                        <h3 class="recipe-title"><?= htmlspecialchars($r['title']) ?></h3>
                                        <div class="recipe-meta">
                                            <div class="match-score">
                                                <div class="score-circle" style="--percentage: <?= $match_percentage ?>%; 
                                                    --color: <?= $match_percentage >= 80 ? '#10b981' : ($match_percentage >= 50 ? '#f59e0b' : '#ef4444') ?>">
                                                    <span><?= $match_percentage ?>%</span>
                                                </div>
                                                <small>Eşleşme</small>
                                            </div>
                                            <?php if ($is_complete): ?>
                                                <div class="complete-badge">
                                                    <i class="fas fa-check-circle"></i>
                                                    Tam Malzeme
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <?php if (!empty($r['image_url'])): ?>
                                        <div class="recipe-image">
                                            <img src="<?= htmlspecialchars($r['image_url']) ?>" 
                                                 alt="<?= htmlspecialchars($r['title']) ?>" />
                                        </div>
                                    <?php endif; ?>

                                    <div class="recipe-info">
                                        <?php if (!empty($r['tags']) || !empty($r['prep_minutes']) || !empty($r['calories'])): ?>
                                            <div class="info-grid">
                                                <?php if (!empty($r['prep_minutes'])): ?>
                                                    <div class="info-item">
                                                        <i class="fas fa-clock"></i>
                                                        <span><?= (int)$r['prep_minutes'] ?> dk</span>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($r['calories'])): ?>
                                                    <div class="info-item">
                                                        <i class="fas fa-fire"></i>
                                                        <span><?= (int)$r['calories'] ?> kcal</span>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($r['tags'])): ?>
                                                    <div class="info-item">
                                                        <i class="fas fa-tags"></i>
                                                        <span><?= htmlspecialchars($r['tags']) ?></span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>

                                        <div class="ingredients-section">
                                            <h4><i class="fas fa-shopping-basket"></i> Malzemeler</h4>
                                            <div class="ingredients-chips">
                                                <?php foreach ($have as $h): ?>
                                                    <span class="chip have">
                                                        <i class="fas fa-check"></i>
                                                        <?= htmlspecialchars($h) ?>
                                                    </span>
                                                <?php endforeach; ?>
                                                <?php foreach ($missing as $m): ?>
                                                    <span class="chip miss">
                                                        <i class="fas fa-times"></i>
                                                        <?= htmlspecialchars($m) ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>

                                        <?php if (!empty($r['instructions'])): ?>
                                            <details class="instructions">
                                                <summary>
                                                    <i class="fas fa-book-open"></i>
                                                    Yapılışı
                                                </summary>
                                                <div class="instructions-content">
                                                    <?= nl2br(htmlspecialchars($r['instructions'])) ?>
                                                </div>
                                            </details>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state large">
                            <i class="fas fa-search"></i>
                            <h3>Tarif bulunamadı</h3>
                            <p>Farklı malzemeler seçerek veya arama terimini değiştirerek tekrar deneyin.</p>
                            <a href="?" class="btn-primary">
                                <i class="fas fa-refresh"></i> Sıfırla
                            </a>
                        </div>
                    <?php endif; ?>
                </section>
            </main>
        </div>

        <footer class="footer">
            <p>Akıllı Yemek Öneri Sistemi &copy; <?= date('Y') ?></p>
        </footer>
    </div>

    <script>
        // Dinamik arama
        document.getElementById('searchInput').addEventListener('input', function(e) {
            clearTimeout(this.searchTimeout);
            this.searchTimeout = setTimeout(() => {
                document.getElementById('searchForm').submit();
            }, 500);
        });

        // Malzeme checkbox stil
        document.querySelectorAll('.ingredient-checkbox input').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                this.parentElement.classList.toggle('selected', this.checked);
            });
        });
    </script>
</body>
</html>