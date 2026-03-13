<?php
// ─── En-têtes ─────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ─── Connexion DB ─────────────────────────────────────────────
require_once __DIR__ . '/config.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Connexion base de données impossible.']);
    exit;
}

// ─── Création / migration des tables ─────────────────────────

// Table commandes (avec colonne notes)
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `orders` (
        `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `created_at` DATETIME      NOT NULL DEFAULT NOW(),
        `client`     VARCHAR(255)  DEFAULT NULL,
        `items`      JSON          NOT NULL,
        `total`      DECIMAL(10,2) NOT NULL,
        `pay`        VARCHAR(50)   NOT NULL,
        `status`     VARCHAR(50)   NOT NULL DEFAULT 'attente',
        `notes`      TEXT          DEFAULT NULL,
        `updated_at` TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Migration douce : ajoute la colonne notes si la table existait déjà sans elle
try {
    $pdo->exec("ALTER TABLE `orders` ADD COLUMN `notes` TEXT DEFAULT NULL");
} catch (PDOException $e) { /* colonne déjà présente – on ignore */ }

// Migration douce : colonne archived (0 = session courante, 1 = archivé)
try {
    $pdo->exec("ALTER TABLE `orders` ADD COLUMN `archived` TINYINT(1) NOT NULL DEFAULT 0");
    $pdo->exec("ALTER TABLE `orders` ADD INDEX `idx_archived` (`archived`)");
} catch (PDOException $e) { /* colonne déjà présente – on ignore */ }

// Migration douce : colonne session_id
try {
    $pdo->exec("ALTER TABLE `orders` ADD COLUMN `session_id` INT UNSIGNED DEFAULT NULL");
    $pdo->exec("ALTER TABLE `orders` ADD INDEX `idx_session_id` (`session_id`)");
} catch (PDOException $e) { /* colonne déjà présente – on ignore */ }

// Table articles personnalisés
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `custom_items` (
        `id`    INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
        `cat`   VARCHAR(50)   NOT NULL DEFAULT 'salee',
        `name`  VARCHAR(255)  NOT NULL,
        `descr` VARCHAR(500)  NOT NULL DEFAULT '',
        `price` DECIMAL(10,2) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Table sessions nommées
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `sessions` (
        `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `name`       VARCHAR(255) NOT NULL DEFAULT '',
        `lieu`       VARCHAR(255) NOT NULL DEFAULT '',
        `date`       DATE         NOT NULL,
        `created_at` DATETIME     NOT NULL DEFAULT NOW(),
        `closed_at`  DATETIME     DEFAULT NULL,
        `is_active`  TINYINT(1)   NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ─── Migration backward compat ────────────────────────────────
// Si des commandes actives (archived=0) n'ont pas de session_id
// et qu'il n'existe pas de session active → créer une session de reprise
$hasActive = (int) $pdo->query("SELECT COUNT(*) FROM `sessions` WHERE is_active = 1")->fetchColumn();
if ($hasActive === 0) {
    $orphans = (int) $pdo->query("SELECT COUNT(*) FROM `orders` WHERE archived = 0 AND session_id IS NULL")->fetchColumn();
    if ($orphans > 0) {
        $minDate  = $pdo->query("SELECT DATE(MIN(created_at)) FROM `orders` WHERE archived = 0 AND session_id IS NULL")->fetchColumn();
        $safeDate = (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$minDate)) ? $minDate : date('Y-m-d');
        $stmt     = $pdo->prepare("INSERT INTO `sessions` (name, lieu, date, is_active) VALUES ('Session reprise', '', :date, 1)");
        $stmt->execute([':date' => $safeDate]);
        $newSessId = (int) $pdo->lastInsertId();
        $pdo->exec("UPDATE `orders` SET session_id = {$newSessId} WHERE archived = 0 AND session_id IS NULL");
    }
}

// ─── Routeur ─────────────────────────────────────────────────
$action = $_GET['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
if (!$action && !empty($body['action'])) $action = $body['action'];

switch ($action) {

    // ── SESSIONS ──────────────────────────────────────────────

    case 'get_sessions':
        // Toutes les sessions avec agrégats
        $rows = $pdo->query("
            SELECT s.*,
                   COUNT(o.id)        AS order_count,
                   IFNULL(SUM(CASE WHEN o.status != 'annule' THEN o.total ELSE 0 END), 0) AS total_ca
            FROM `sessions` s
            LEFT JOIN `orders` o ON o.session_id = s.id
            GROUP BY s.id
            ORDER BY s.date DESC, s.created_at DESC
        ")->fetchAll();
        foreach ($rows as &$r) {
            $r['id']          = (int)   $r['id'];
            $r['is_active']   = (int)   $r['is_active'];
            $r['order_count'] = (int)   $r['order_count'];
            $r['total_ca']    = (float) $r['total_ca'];
        }
        unset($r);
        echo json_encode(['ok' => true, 'sessions' => $rows], JSON_UNESCAPED_UNICODE);
        break;

    case 'create_session':
        if (empty($body['name']) || empty($body['lieu']) || empty($body['date'])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Données manquantes (name, lieu, date).']);
            break;
        }
        // Désactiver l'ancienne session active
        $pdo->exec("UPDATE `sessions` SET is_active = 0 WHERE is_active = 1");
        // Créer la nouvelle
        $stmt = $pdo->prepare("
            INSERT INTO `sessions` (name, lieu, date, is_active)
            VALUES (:name, :lieu, :date, 1)
        ");
        $stmt->execute([
            ':name' => trim($body['name']),
            ':lieu' => trim($body['lieu']),
            ':date' => $body['date'],
        ]);
        $id = (int) $pdo->lastInsertId();
        $sess = $pdo->query("SELECT * FROM `sessions` WHERE id = {$id}")->fetch();
        $sess['id']        = (int) $sess['id'];
        $sess['is_active'] = (int) $sess['is_active'];
        echo json_encode(['ok' => true, 'session' => $sess], JSON_UNESCAPED_UNICODE);
        break;

    case 'get_session_orders':
        if (empty($body['session_id'])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'session_id manquant.']);
            break;
        }
        $sid  = (int) $body['session_id'];
        $sess = $pdo->query("SELECT * FROM `sessions` WHERE id = {$sid}")->fetch();
        if (!$sess) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Session introuvable.']);
            break;
        }
        $sess['id']        = (int) $sess['id'];
        $sess['is_active'] = (int) $sess['is_active'];
        $rows = $pdo->query("SELECT * FROM `orders` WHERE session_id = {$sid} ORDER BY id ASC")->fetchAll();
        foreach ($rows as &$r) {
            $r['items'] = json_decode($r['items'], true);
            $r['total'] = (float) $r['total'];
            $r['id']    = (int)   $r['id'];
        }
        unset($r);
        echo json_encode(['ok' => true, 'session' => $sess, 'orders' => $rows], JSON_UNESCAPED_UNICODE);
        break;

    // ── COMMANDES ─────────────────────────────────────────────

    case 'get_orders':
        $rows = $pdo->query("SELECT * FROM `orders` WHERE archived = 0 ORDER BY id ASC")->fetchAll();
        foreach ($rows as &$r) {
            $r['items'] = json_decode($r['items'], true);
            $r['total'] = (float) $r['total'];
            $r['id']    = (int)   $r['id'];
        }
        unset($r);
        $next = (int) $pdo->query("SELECT IFNULL(MAX(id) + 1, 1) FROM `orders`")->fetchColumn();
        // Session active courante
        $curSess = $pdo->query("SELECT id, name, lieu, date FROM `sessions` WHERE is_active = 1 LIMIT 1")->fetch();
        if ($curSess) $curSess['id'] = (int) $curSess['id'];
        echo json_encode([
            'ok'              => true,
            'orders'          => $rows,
            'next_num'        => $next,
            'ts'              => time(),
            'current_session' => $curSess ?: null,
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'create_order':
        if (empty($body['items']) || !isset($body['total']) || empty($body['pay'])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Données manquantes.']);
            break;
        }
        // Récupérer la session active
        $activeSessId = $pdo->query("SELECT id FROM `sessions` WHERE is_active = 1 LIMIT 1")->fetchColumn();
        $stmt = $pdo->prepare("
            INSERT INTO `orders` (client, items, total, pay, notes, session_id)
            VALUES (:client, :items, :total, :pay, :notes, :session_id)
        ");
        $stmt->execute([
            ':client'     => $body['client'] ?: null,
            ':items'      => json_encode($body['items'], JSON_UNESCAPED_UNICODE),
            ':total'      => (float) $body['total'],
            ':pay'        => $body['pay'],
            ':notes'      => !empty($body['notes']) ? trim($body['notes']) : null,
            ':session_id' => $activeSessId ? (int) $activeSessId : null,
        ]);
        $id = (int) $pdo->lastInsertId();
        echo json_encode(['ok' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);
        break;

    case 'update_status':
        if (empty($body['id']) || empty($body['status'])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'id ou status manquant.']);
            break;
        }
        $allowed = ['attente', 'pret', 'servi', 'annule'];
        if (!in_array($body['status'], $allowed, true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Statut invalide.']);
            break;
        }
        $stmt = $pdo->prepare("UPDATE `orders` SET status = :s WHERE id = :id");
        $stmt->execute([':s' => $body['status'], ':id' => (int) $body['id']]);
        echo json_encode(['ok' => true]);
        break;

    case 'clear_session':
        // Archive les commandes de la session courante (ne supprime pas – conserve pour les archives)
        $pdo->exec("UPDATE `orders` SET archived = 1 WHERE archived = 0");
        // Clôturer la session active
        $pdo->exec("UPDATE `sessions` SET is_active = 0, closed_at = NOW() WHERE is_active = 1");
        echo json_encode(['ok' => true]);
        break;

    // ── ARTICLES PERSONNALISÉS ────────────────────────────────

    case 'get_custom_items':
        $rows = $pdo->query("SELECT * FROM `custom_items` ORDER BY id ASC")->fetchAll();
        foreach ($rows as &$r) {
            $r['id']    = (int)   $r['id'];
            $r['price'] = (float) $r['price'];
        }
        unset($r);
        echo json_encode(['ok' => true, 'items' => $rows], JSON_UNESCAPED_UNICODE);
        break;

    case 'create_item':
        if (empty($body['name']) || !isset($body['price']) || empty($body['cat'])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Données manquantes.']);
            break;
        }
        $cats = ['salee', 'sucree', 'boisson'];
        if (!in_array($body['cat'], $cats, true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Catégorie invalide.']);
            break;
        }
        $stmt = $pdo->prepare("
            INSERT INTO `custom_items` (cat, name, descr, price)
            VALUES (:cat, :name, :descr, :price)
        ");
        $stmt->execute([
            ':cat'   => $body['cat'],
            ':name'  => trim($body['name']),
            ':descr' => trim($body['descr'] ?? ''),
            ':price' => (float) $body['price'],
        ]);
        $id = (int) $pdo->lastInsertId();
        echo json_encode(['ok' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);
        break;

    case 'delete_item':
        if (empty($body['id'])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'id manquant.']);
            break;
        }
        $stmt = $pdo->prepare("DELETE FROM `custom_items` WHERE id = :id");
        $stmt->execute([':id' => (int) $body['id']]);
        echo json_encode(['ok' => true]);
        break;

    // ── ARCHIVES ──────────────────────────────────────────────

    case 'get_archives':
        $period    = $body['period'] ?? 'month';
        switch ($period) {
            case 'week':  $interval = '7 DAY';    break;
            case 'year':  $interval = '365 DAY';  break;
            default:      $interval = '30 DAY';   break;  // month
        }
        $stmt = $pdo->prepare(
            "SELECT * FROM `orders`
              WHERE archived = 1
                AND created_at >= DATE_SUB(NOW(), INTERVAL {$interval})
              ORDER BY created_at ASC"
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['items']    = json_decode($r['items'], true);
            $r['total']    = (float) $r['total'];
            $r['id']       = (int)   $r['id'];
            $r['archived'] = (int)   $r['archived'];
        }
        unset($r);
        echo json_encode(['ok' => true, 'orders' => $rows], JSON_UNESCAPED_UNICODE);
        break;

    // ── ENVOI EMAIL EXPORT ───────────────────────────────────
    case 'send_export_email':
        $email = filter_var($body['email'] ?? '', FILTER_VALIDATE_EMAIL);
        if (!$email) {
            echo json_encode(['ok' => false, 'error' => 'Adresse email invalide']);
            break;
        }
        $sessName = htmlspecialchars($body['sess_name'] ?? 'Session', ENT_QUOTES);
        $sessLieu = htmlspecialchars($body['sess_lieu'] ?? '', ENT_QUOTES);
        $sessDate = $body['sess_date'] ?? '';
        if ($sessDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $sessDate)) {
            $dt = new DateTime($sessDate);
            $formatter = new IntlDateFormatter('fr_FR', IntlDateFormatter::LONG, IntlDateFormatter::NONE);
            $sessDateFr = $formatter ? $formatter->format($dt) : $dt->format('d/m/Y');
        } else {
            $sessDateFr = $sessDate;
        }
        $exportDt  = (new DateTime())->format('d/m/Y à H:i');
        $rawOrders = $body['orders'] ?? [];

        $PL = ['especes' => 'Espèces', 'carte' => 'Carte bancaire', 'ticket' => 'Ticket-Restaurant'];
        $SB = ['attente' => 'En attente', 'pret' => 'Prêt', 'servi' => 'Servi', 'annule' => 'Annulé'];

        $paid      = array_values(array_filter($rawOrders, fn($o) => ($o['status'] ?? '') !== 'annule'));
        $totalCA   = array_sum(array_column($paid, 'total'));
        $count     = count($paid);
        $avg       = $count ? $totalCA / $count : 0;
        $cancelled = count(array_filter($rawOrders, fn($o) => ($o['status'] ?? '') === 'annule'));

        // Répartition paiements
        $payT = [];
        foreach ($paid as $o) { $p = $o['pay'] ?? ''; $payT[$p] = ($payT[$p] ?? 0) + ($o['total'] ?? 0); }

        // Top articles
        $itemCount = [];
        foreach ($paid as $o) {
            $items = is_array($o['items']) ? $o['items'] : json_decode($o['items'] ?? '[]', true);
            foreach ($items as $i) { $n = $i['name'] ?? ''; $itemCount[$n] = ($itemCount[$n] ?? 0) + ($i['qty'] ?? 1); }
        }
        arsort($itemCount);

        // ── Génération HTML email ─────────────────────────────
        $css = 'body{font-family:"Segoe UI",Arial,sans-serif;color:#222;margin:0;padding:0;background:#f4f4f4}'
             . '.wrap{max-width:640px;margin:20px auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.1)}'
             . '.head{background:#1a1008;padding:24px 28px;color:#fff}'
             . '.head h1{margin:0 0 4px;font-size:20px;color:#e8873a}'
             . '.head p{margin:0;font-size:12px;color:#9a7a5a}'
             . '.body{padding:24px 28px}'
             . '.kpis{display:flex;gap:12px;margin-bottom:24px;flex-wrap:wrap}'
             . '.kpi{flex:1;min-width:100px;background:#fffaf5;border:1px solid #e0d0c0;border-radius:8px;padding:12px;text-align:center}'
             . '.kpi .val{font-size:20px;font-weight:900;color:#c97d2e}'
             . '.kpi .lbl{font-size:10px;text-transform:uppercase;color:#888;letter-spacing:.4px;margin-top:3px}'
             . 'h2{font-size:12px;font-weight:800;margin:20px 0 8px;border-bottom:2px solid #f0e0d0;padding-bottom:3px;text-transform:uppercase;letter-spacing:.4px;color:#6b3f1a}'
             . 'table{width:100%;border-collapse:collapse;font-size:12px;margin-bottom:4px}'
             . 'th{text-align:left;background:#f8f0e8;padding:6px 8px;font-size:11px;text-transform:uppercase;color:#886040;border-bottom:1px solid #e8d8c8}'
             . 'td{padding:5px 8px;border-bottom:1px solid #f4ece4;vertical-align:top}'
             . 'tr.tot td{background:#f8f0e8;font-weight:700;border-top:1px solid #e0c8b0}'
             . '.ora{color:#c97d2e;font-weight:700}'
             . '.ann{opacity:.5}'
             . '.footer{background:#f8f0e8;padding:12px 28px;font-size:10px;color:#9a7a5a;text-align:center}';

        // Tableau paiements
        $payRows = '';
        foreach ($payT as $pay => $amt) {
            $nb  = count(array_filter($paid, fn($o) => ($o['pay'] ?? '') === $pay));
            $pct = $totalCA > 0 ? round($amt / $totalCA * 100) : 0;
            $payRows .= '<tr><td>' . ($PL[$pay] ?? $pay) . '</td>'
                      . '<td class="ora">' . number_format($amt, 2, ',', '') . ' €</td>'
                      . '<td>' . $pct . ' %</td><td>' . $nb . '</td></tr>';
        }
        $payRows .= '<tr class="tot"><td>Total</td>'
                  . '<td class="ora">' . number_format($totalCA, 2, ',', '') . ' €</td>'
                  . '<td>100 %</td><td>' . $count . '</td></tr>';

        // Tableau articles
        $itemRows = '';
        foreach ($itemCount as $name => $qty) {
            $itemRows .= '<tr><td>' . htmlspecialchars($name, ENT_QUOTES) . '</td>'
                       . '<td><strong>' . $qty . '</strong></td></tr>';
        }

        // Tableau commandes
        $orderRows = '';
        $reversed = array_reverse($rawOrders);
        foreach ($reversed as $o) {
            $oItems = is_array($o['items']) ? $o['items'] : json_decode($o['items'] ?? '[]', true);
            $itemStr = implode(', ', array_map(fn($i) => ($i['qty'] ?? 1) . '× ' . htmlspecialchars($i['name'] ?? '', ENT_QUOTES), $oItems));
            $notes   = !empty($o['notes']) ? '<br><em style="color:#888">📝 ' . htmlspecialchars($o['notes'], ENT_QUOTES) . '</em>' : '';
            $time    = substr($o['created_at'] ?? '--:--', 11, 5);
            $cls     = ($o['status'] ?? '') === 'annule' ? ' class="ann"' : '';
            $orderRows .= '<tr' . $cls . '>'
                        . '<td class="ora">#' . (int)$o['id'] . '</td>'
                        . '<td>' . $time . '</td>'
                        . '<td>' . htmlspecialchars($o['client'] ?? '—', ENT_QUOTES) . '</td>'
                        . '<td>' . $itemStr . $notes . '</td>'
                        . '<td class="ora">' . number_format((float)($o['total'] ?? 0), 2, ',', '') . ' €</td>'
                        . '<td>' . ($PL[$o['pay'] ?? ''] ?? ($o['pay'] ?? '')) . '</td>'
                        . '<td>' . ($SB[$o['status'] ?? ''] ?? ($o['status'] ?? '')) . '</td>'
                        . '</tr>';
        }

        $metaLine = $sessLieu ? "📍 $sessLieu" : '';
        if ($sessDateFr) $metaLine .= ($metaLine ? ' &nbsp;·&nbsp; ' : '') . "📅 $sessDateFr";

        $html = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Export</title>'
              . '<style>' . $css . '</style></head><body>'
              . '<div class="wrap">'
              . '<div class="head"><h1>🥞 Crêpes et Galettes de Gérard</h1>'
              . '<p><strong>' . $sessName . '</strong>' . ($metaLine ? ' &nbsp;·&nbsp; ' . $metaLine : '') . '</p>'
              . '<p style="margin-top:4px">Exporté le ' . $exportDt . '</p></div>'
              . '<div class="body">'
              . '<div class="kpis">'
              . '<div class="kpi"><div class="val">' . $count . '</div><div class="lbl">Commandes</div></div>'
              . '<div class="kpi"><div class="val">' . number_format($totalCA, 2, ',', '') . ' €</div><div class="lbl">CA total</div></div>'
              . '<div class="kpi"><div class="val">' . number_format($avg, 2, ',', '') . ' €</div><div class="lbl">Panier moyen</div></div>'
              . '<div class="kpi"><div class="val">' . $cancelled . '</div><div class="lbl">Annulées</div></div>'
              . '</div>'
              . '<h2>💳 Répartition des paiements</h2>'
              . '<table><tr><th>Mode</th><th>Montant</th><th>%</th><th>Nb</th></tr>' . $payRows . '</table>'
              . '<h2>🏆 Articles vendus</h2>'
              . '<table><tr><th>Article</th><th>Qté</th></tr>' . $itemRows . '</table>'
              . '<h2>🧾 Détail des commandes</h2>'
              . '<table><tr><th>#</th><th>Heure</th><th>Client</th><th>Articles</th><th>Total</th><th>Paiement</th><th>Statut</th></tr>'
              . $orderRows . '</table>'
              . '</div>'
              . '<div class="footer">Crêpes et Galettes de Gérard &nbsp;·&nbsp; galettes.cloud &nbsp;·&nbsp; ' . $exportDt . '</div>'
              . '</div></body></html>';

        $subject = '=?UTF-8?B?' . base64_encode('Export session – ' . ($body['sess_name'] ?? 'Session')) . '?=';
        $headers  = "MIME-Version: 1.0\r\n"
                  . "Content-Type: text/html; charset=UTF-8\r\n"
                  . "From: Galettes de Gérard <noreply@galettes.cloud>\r\n"
                  . "X-Mailer: PHP/" . phpversion();
        $sent = mail($email, $subject, $html, $headers);
        echo json_encode(['ok' => $sent, 'error' => $sent ? null : 'Échec envoi mail (vérifiez la config serveur)']);
        break;

    // ── DÉFAUT ───────────────────────────────────────────────
    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => "Action inconnue : {$action}"]);
        break;
}
