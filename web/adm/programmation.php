<?php
// ============================================================
//  WebRadio – Programmation du jour
// ============================================================
require_once __DIR__ . '/config.php';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function fmtDuree(int $s): string { return sprintf('%d:%02d', intdiv($s, 60), $s % 60); }

session_start();
function flash(string $type, string $msg): void { $_SESSION['flash'] = ['type' => $type, 'msg' => $msg]; }
function getFlash(): ?array {
    if (!isset($_SESSION['flash'])) return null;
    $f = $_SESSION['flash']; unset($_SESSION['flash']); return $f;
}

// ── Date sélectionnée ────────────────────────────────────────
$today    = date('Y-m-d');
$date_sel = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : $today;

// ════════════════════════════════════════════════════════════
//  ACTIONS POST
// ════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        $pdo = db();
        switch ($action) {

            case 'add_prog':
                $date     = $_POST['date_prog'] ?? $date_sel;
                $id_titre = (int)($_POST['id_titre'] ?? 0);
                if (!$id_titre) throw new Exception("Titre non sélectionné.");

                // 1. Récupérer les infos du titre qu'on veut ajouter
                $req_titre = $pdo->prepare("SELECT id_artiste, nom_titre FROM titre WHERE id_titre = ?");
                $req_titre->execute([$id_titre]);
                $titre_concerne = $req_titre->fetch(PDO::FETCH_ASSOC);
                $id_artiste_concerne = $titre_concerne['id_artiste'];

                // 2. Vérification 1 : Est-ce le même artiste que le tout dernier morceau programmé ?
                $req_dernier = $pdo->prepare("
                    SELECT t.id_artiste, a.nom_artiste FROM programmation p
                    JOIN titre t ON t.id_titre = p.id_titre
                    JOIN artiste a ON a.id_artiste = t.id_artiste
                    WHERE p.date_prog = ? ORDER BY p.ordre DESC LIMIT 1
                ");
                $req_dernier->execute([$date]);
                $dernier_morceau = $req_dernier->fetch(PDO::FETCH_ASSOC);

                if ($dernier_morceau && $dernier_morceau['id_artiste'] == $id_artiste_concerne) {
                    throw new Exception("RÈGLE STRICTE : Impossible d'enchaîner deux morceaux de l'artiste « " . $dernier_morceau['nom_artiste'] . " ».");
                }

                // 3. Vérification 2 : Règles de fenêtres temporelles (2h Artiste / 6h Titre)
                $check_rot = $pdo->prepare("
                    SELECT 
                        (SELECT nom_artiste FROM artiste WHERE id_artiste = :id_art AND derniere_diffusion >= NOW() - INTERVAL 2 HOUR) as artiste_bloque,
                        (SELECT nom_titre FROM titre WHERE id_titre = :id_titre AND derniere_diffusion >= NOW() - INTERVAL 6 HOUR) as titre_bloque
                ");
                $check_rot->execute(['id_art' => $id_artiste_concerne, 'id_titre' => $id_titre]);
                $bloquage = $check_rot->fetch(PDO::FETCH_ASSOC);

                if ($bloquage['artiste_bloque']) {
                    throw new Exception("ROTATION : Cet artiste a joué il y a moins de 2 heures.");
                }
                if ($bloquage['titre_bloque']) {
                    throw new Exception("ROTATION : Le titre « " . $bloquage['titre_bloque'] . " » a joué il y a moins de 6 heures.");
                }

                // 4. Si tout est OK, on procède à l'insertion manuelle classique
                $max = $pdo->prepare("SELECT COALESCE(MAX(ordre),0) FROM programmation WHERE date_prog=?");
                $max->execute([$date]);
                $ordre = (int)$max->fetchColumn() + 1;
                
                $pdo->prepare("INSERT INTO programmation (date_prog, ordre, id_titre) VALUES (?,?,?)")
                    ->execute([$date, $ordre, $id_titre]);

                // On met à jour ses compteurs immédiatement
                $now = date('Y-m-d H:i:s');
                $pdo->prepare("UPDATE artiste SET derniere_diffusion = ?, compteur_diffusion = compteur_diffusion + 1 WHERE id_artiste = ?")->execute([$now, $id_artiste_concerne]);
                $pdo->prepare("UPDATE titre SET derniere_diffusion = ?, compteur_diffusion = compteur_diffusion + 1 WHERE id_titre = ?")->execute([$now, $id_titre]);

                flash('ok', "Titre ajouté manuellement en position $ordre (Règles validées).");
                break;

            case 'del_prog':
                $id = (int)($_POST['id_prog'] ?? 0);
                $d  = $_POST['date_prog'] ?? $date_sel;
                $pdo->prepare("DELETE FROM programmation WHERE id_prog=?")->execute([$id]);
                // Recalcul des ordres
                $rows = $pdo->prepare("SELECT id_prog FROM programmation WHERE date_prog=? ORDER BY ordre");
                $rows->execute([$d]);
                $upd  = $pdo->prepare("UPDATE programmation SET ordre=? WHERE id_prog=?");
                foreach (array_values($rows->fetchAll()) as $i => $r) {
                    $upd->execute([$i + 1, $r['id_prog']]);
                }
                flash('ok', "Passage supprimé.");
                break;

            case 'move_up':
            case 'move_down':
                $id    = (int)($_POST['id_prog'] ?? 0);
                $d     = $_POST['date_prog'] ?? $date_sel;
                $ordre = (int)($_POST['ordre'] ?? 0);
                $dir   = $action === 'move_up' ? -1 : 1;
                $cible_ordre = $ordre + $dir;
                // Chercher le voisin
                $voisin = $pdo->prepare("SELECT id_prog FROM programmation WHERE date_prog=? AND ordre=?");
                $voisin->execute([$d, $cible_ordre]);
                $v = $voisin->fetchColumn();
                if ($v) {
                    $pdo->prepare("UPDATE programmation SET ordre=? WHERE id_prog=?")->execute([$ordre, $v]);
                    $pdo->prepare("UPDATE programmation SET ordre=? WHERE id_prog=?")->execute([$cible_ordre, $id]);
                }
                flash('ok', "Ordre mis à jour.");
                break;

            case 'copy_day':
                $src = $_POST['date_src'] ?? '';
                $dst = $_POST['date_dst'] ?? '';
                if (!$src || !$dst || $src === $dst) throw new Exception("Dates invalides.");
                // Vider la cible
                $pdo->prepare("DELETE FROM programmation WHERE date_prog=?")->execute([$dst]);
                // Copier
                $pdo->prepare("INSERT INTO programmation (date_prog, ordre, id_titre)
                               SELECT ?, ordre, id_titre FROM programmation WHERE date_prog=? ORDER BY ordre")
                    ->execute([$dst, $src]);
                flash('ok', "Programmation copiée de $src vers $dst.");
                break;

            // ── GÉNÉRATION AUTOMATIQUE ANTICLONAGE ─────────────────
            case 'generate_auto':
                $date = $_POST['date_prog'] ?? $date_sel;
                $nb_morceaux_a_generer = 300;

                for ($m = 0; $m < $nb_morceaux_a_generer; $m++) {
                    // A. On cherche l'ID du dernier artiste inséré (qu'il soit issu d'un ajout manuel ou auto)
                    $req_dernier = $pdo->prepare("
                        SELECT t.id_artiste FROM programmation p
                        JOIN titre t ON t.id_titre = p.id_titre
                        WHERE p.date_prog = ? ORDER BY p.ordre DESC LIMIT 1
                    ");
                    $req_dernier->execute([$date]);
                    $dernier_artiste_id = $req_dernier->fetchColumn() ?: 0;

                    // B. Requête Entonnoir (Priorité aux règles : 2h Artiste / 6h Titre)
                    $sql = "
                        SELECT t.id_titre, t.id_artiste FROM titre t
                        JOIN artiste a ON t.id_artiste = a.id_artiste
                        WHERE a.id_artiste != :dernier_artiste_id
                          AND (a.derniere_diffusion < NOW() - INTERVAL 2 HOUR OR a.derniere_diffusion IS NULL)
                          AND (t.derniere_diffusion < NOW() - INTERVAL 6 HOUR OR t.derniere_diffusion IS NULL)
                        ORDER BY RAND() LIMIT 1
                    ";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute(['dernier_artiste_id' => $dernier_artiste_id]);
                    $morceau = $stmt->fetch(PDO::FETCH_ASSOC);

                    // C. Mode Secours (Si la base est trop petite et que tout est verrouillé par le temps)
                    if (!$morceau) {
                        $sql_secours = "
                            SELECT t.id_titre, t.id_artiste FROM titre t
                            WHERE t.id_artiste != :dernier_artiste_id
                            ORDER BY t.derniere_diffusion ASC LIMIT 1
                        ";
                        $stmt_secours = $pdo->prepare($sql_secours);
                        $stmt_secours->execute(['dernier_artiste_id' => $dernier_artiste_id]);
                        $morceau = $stmt_secours->fetch(PDO::FETCH_ASSOC);
                    }

                    // D. Insertion à la suite
                    if ($morceau) {
                        $max = $pdo->prepare("SELECT COALESCE(MAX(ordre),0) FROM programmation WHERE date_prog=?");
                        $max->execute([$date]);
                        $ordre = (int)$max->fetchColumn() + 1;

                        $pdo->prepare("INSERT INTO programmation (date_prog, ordre, id_titre) VALUES (?,?,?)")
                            ->execute([$date, $ordre, $morceau['id_titre']]);

                        // On simule l'avancement pour le calcul du tour de boucle suivant
                        $fake_now = date('Y-m-d H:i:s', strtotime("+$m minutes"));
                        $pdo->prepare("UPDATE artiste SET derniere_diffusion = ? WHERE id_artiste = ?")->execute([$fake_now, $morceau['id_artiste']]);
                        $pdo->prepare("UPDATE titre SET derniere_diffusion = ? WHERE id_titre = ?")->execute([$fake_now, $morceau['id_titre']]);
                    } else {
                        break;
                    }
                }
                // 🟢 NOUVEAUTÉ : Notifier le superviseur Python de recharger la playlist en mémoire
                $ch = curl_init('http://127.0.0.1:8089/reload_schedule');
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 2);
                curl_exec($ch);
                curl_close($ch);

                flash('ok', "Playlist automatique de $nb_morceaux_a_generer morceaux injectée et synchronisée avec le Superviseur !");
                break;    
            default:
                flash('err', "Action inconnue.");
        }
    } catch (Exception $e) {
        flash('err', "Erreur : " . $e->getMessage());
    }
    $dp = urlencode($_POST['date_prog'] ?? $date_sel);
    header("Location: programmation.php?date=$dp");
    exit;
}

// ════════════════════════════════════════════════════════════
//  LECTURE
// ════════════════════════════════════════════════════════════
$pdo = db();

$prog = $pdo->prepare("
    SELECT p.id_prog, p.ordre, p.id_titre,
           t.nom_titre, t.duree, t.chemin,
           a.nom_artiste, g.nom_genre
    FROM programmation p
    JOIN titre   t ON t.id_titre   = p.id_titre
    JOIN artiste a ON a.id_artiste = t.id_artiste
    LEFT JOIN genre g ON g.id_genre = t.id_genre
    WHERE p.date_prog = ?
    ORDER BY p.ordre
");
$prog->execute([$date_sel]);
$items = $prog->fetchAll();

$titres   = $pdo->query("
    SELECT t.id_titre, t.nom_titre, t.duree, a.nom_artiste
    FROM titre t JOIN artiste a ON a.id_artiste = t.id_artiste
    ORDER BY a.nom_artiste, t.nom_titre
")->fetchAll();

// Durée totale
$duree_totale = array_sum(array_column($items, 'duree'));

$flash = getFlash();

// Formater la date affichée
$date_obj     = new DateTime($date_sel);
$jours_fr     = ['dimanche','lundi','mardi','mercredi','jeudi','vendredi','samedi'];
$mois_fr      = ['','janvier','février','mars','avril','mai','juin','juillet',
                  'août','septembre','octobre','novembre','décembre'];
$date_affiche = $jours_fr[(int)$date_obj->format('w')] . ' '
              . (int)$date_obj->format('j') . ' '
              . $mois_fr[(int)$date_obj->format('n')] . ' '
              . $date_obj->format('Y');

// ── Calcul heure de passage (à partir de 00:00) ──────────────
function calcPassage(array $items, int $index): string {
    $s = 0;
    for ($i = 0; $i < $index; $i++) $s += (int)$items[$i]['duree'];
    return sprintf('%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60));
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WebRadio – Programmation</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;800&family=Space+Mono:ital@0;1&display=swap" rel="stylesheet">
<style>
:root {
  --bg:      #0a0a0f;
  --surface: #13131c;
  --card:    #1c1c2a;
  --border:  #2e2e44;
  --accent:  #e8ff47;
  --accent2: #ff4f7b;
  --accent3: #47c5ff;
  --text:    #e8e8f0;
  --muted:   #7070a0;
  --danger:  #ff4f4f;
  --ok:      #47ff9a;
  --radius:  4px;
  --mono:    'Space Mono', monospace;
  --sans:    'Syne', sans-serif;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { background: var(--bg); color: var(--text); font-family: var(--sans); min-height: 100vh; }

/* ── Header ── */
header {
  display: flex; align-items: center; gap: 1.5rem;
  padding: 1.2rem 2rem;
  background: var(--surface);
  border-bottom: 2px solid var(--accent2);
}
.logo { font-size: 1.6rem; font-weight: 800; letter-spacing: -.03em; color: var(--accent); text-decoration: none; }
.logo span { color: var(--accent2); }
.header-links { margin-left: auto; display: flex; gap: 1rem; }
.header-links a { color: var(--muted); text-decoration: none; font-size: .85rem; font-family: var(--mono); transition: color .2s; }
.header-links a:hover { color: var(--accent); }

/* ── Flash ── */
.flash { margin: 1rem 2rem 0; padding: .75rem 1rem; border-left: 4px solid; font-family: var(--mono); font-size: .85rem; border-radius: var(--radius); }
.flash.ok  { border-color: var(--ok);  background: #47ff9a18; color: var(--ok);  }
.flash.err { border-color: var(--danger); background: #ff4f4f18; color: var(--danger); }

/* ── Main layout ── */
.main { display: grid; grid-template-columns: 1fr 320px; gap: 2rem; padding: 2rem; }
@media (max-width: 900px) { .main { grid-template-columns: 1fr; } }

/* ── Date bar ── */
.date-bar {
  display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;
  margin-bottom: 1.8rem;
}
.date-bar h1 { font-size: 1.8rem; font-weight: 800; }
.date-bar h1 .day-name { color: var(--accent2); }
.date-nav { display: flex; gap: .4rem; }
.date-nav a {
  display: inline-block; padding: .4rem .8rem;
  background: var(--card); border: 1px solid var(--border);
  border-radius: var(--radius); color: var(--text);
  text-decoration: none; font-family: var(--mono); font-size: .8rem;
  transition: border-color .2s;
}
.date-nav a:hover { border-color: var(--accent); color: var(--accent); }
.date-input-wrap { margin-left: auto; }
.date-input-wrap input[type=date] {
  background: var(--card); border: 1px solid var(--border);
  color: var(--text); padding: .4rem .7rem;
  border-radius: var(--radius); font-family: var(--mono); font-size: .85rem;
  outline: none; cursor: pointer;
}

/* ── Stats strip ── */
.stats-strip {
  display: flex; gap: 1.5rem; margin-bottom: 1.5rem;
  padding: .8rem 1.2rem;
  background: var(--card); border: 1px solid var(--border);
  border-left: 3px solid var(--accent2);
  border-radius: var(--radius);
  font-family: var(--mono); font-size: .8rem;
}
.stat { display: flex; flex-direction: column; gap: .15rem; }
.stat-val { color: var(--accent); font-size: 1.2rem; font-weight: bold; }
.stat-lbl { color: var(--muted); font-size: .7rem; }

/* ── Timeline ── */
.timeline { display: flex; flex-direction: column; gap: .5rem; }
.prog-item {
  display: grid;
  grid-template-columns: 52px 60px 1fr auto;
  align-items: center;
  gap: .75rem;
  padding: .7rem 1rem;
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  transition: border-color .15s;
}
.prog-item:hover { border-color: var(--accent3); }
.prog-order {
  font-family: var(--mono); font-size: 1.1rem; font-weight: bold;
  color: var(--accent); text-align: center;
}
.prog-time { font-family: var(--mono); font-size: .8rem; color: var(--muted); }
.prog-info { overflow: hidden; }
.prog-title { font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.prog-meta { font-family: var(--mono); font-size: .75rem; color: var(--muted); margin-top: .15rem; }
.prog-meta .badge {
  display: inline-block; padding: .1rem .4rem;
  border-radius: 2px; font-size: .68rem;
  background: var(--surface); border: 1px solid var(--border); color: var(--muted);
  margin-right: .3rem;
}
.prog-actions { display: flex; gap: .35rem; align-items: center; }

/* ── Buttons ── */
.btn { padding: .4rem .9rem; border: none; border-radius: var(--radius); font-family: var(--sans); font-weight: 600; font-size: .8rem; cursor: pointer; transition: opacity .15s, transform .1s; white-space: nowrap; }
.btn:hover { opacity: .85; }
.btn:active { transform: scale(.97); }
.btn-accent  { background: var(--accent);  color: #000; }
.btn-danger  { background: transparent; color: var(--danger); border: 1px solid var(--danger); padding: .3rem .6rem; font-size: .72rem; }
.btn-move    { background: var(--surface); color: var(--muted); border: 1px solid var(--border); padding: .3rem .5rem; font-size: .75rem; }
.btn-move:hover { border-color: var(--accent3); color: var(--accent3); }

/* ── Empty ── */
.empty { text-align: center; padding: 3rem; color: var(--muted); font-family: var(--mono); font-size: .85rem; }

/* ── Sidebar panels ── */
.sidebar { display: flex; flex-direction: column; gap: 1.5rem; }
.panel {
  background: var(--card);
  border: 1px solid var(--border);
  border-top: 3px solid var(--accent3);
  border-radius: var(--radius);
  padding: 1.2rem;
}
.panel-title { font-size: .8rem; font-weight: 800; text-transform: uppercase; letter-spacing: .1em; color: var(--accent3); margin-bottom: 1rem; }
.field { display: flex; flex-direction: column; gap: .3rem; margin-bottom: .75rem; }
.field label { font-size: .7rem; font-family: var(--mono); color: var(--muted); text-transform: uppercase; letter-spacing: .08em; }
.field input, .field select {
  background: var(--surface); border: 1px solid var(--border); color: var(--text);
  padding: .45rem .7rem; border-radius: var(--radius); font-family: var(--mono);
  font-size: .83rem; outline: none; transition: border-color .2s; width: 100%;
}
.field input:focus, .field select:focus { border-color: var(--accent3); }
.field select option { background: var(--surface); }
.panel-sep { border: none; border-top: 1px solid var(--border); margin: 1rem 0; }
.copy-panel { border-top-color: var(--accent); }

/* ── Today badge ── */
.today-badge {
  font-family: var(--mono); font-size: .7rem; padding: .2rem .6rem;
  background: var(--accent2); color: #fff; border-radius: 2px; vertical-align: middle;
}
</style>
</head>
<body>

<header>
  <a class="logo" href="editor.php">Web<span>Radio</span></a>
  <div class="header-links">
    <a href="programmation.php">📅 Programmation</a>
    <a href="editor.php">🎛 Éditeur</a>
  </div>
</header>

<?php if ($flash): ?>
<div class="flash <?= $flash['type'] === 'ok' ? 'ok' : 'err' ?>">
  <?= h($flash['msg']) ?>
</div>
<?php endif; ?>

<?php
// Dates nav
$prev_date = (new DateTime($date_sel))->modify('-1 day')->format('Y-m-d');
$next_date = (new DateTime($date_sel))->modify('+1 day')->format('Y-m-d');
?>

<div class="main">

<!-- ══ COLONNE GAUCHE : planning ══ -->
<div>
  <div class="date-bar">
    <h1>
      <span class="day-name"><?= ucfirst($date_affiche) ?></span>
      <?php if ($date_sel === $today): ?>
        <span class="today-badge">Aujourd'hui</span>
      <?php endif; ?>
    </h1>
    <div class="date-nav">
      <a href="?date=<?= $prev_date ?>">‹ Veille</a>
      <a href="?date=<?= $today ?>">Aujourd'hui</a>
      <a href="?date=<?= $next_date ?>">Lendemain ›</a>
    </div>
    <div class="date-input-wrap">
      <input type="date" id="date-picker" value="<?= $date_sel ?>"
             onchange="location.href='?date='+this.value">
    </div>
  </div>

  <!-- Stats -->
  <div class="stats-strip">
    <div class="stat">
      <span class="stat-val"><?= count($items) ?></span>
      <span class="stat-lbl">Passages</span>
    </div>
    <div class="stat">
      <span class="stat-val"><?= fmtDuree($duree_totale) ?></span>
      <span class="stat-lbl">Durée totale</span>
    </div>
    <div class="stat">
      <span class="stat-val"><?= $duree_totale > 0 ? round($duree_totale / 3600, 1) . 'h' : '—' ?></span>
      <span class="stat-lbl">Heures programmées</span>
    </div>
  </div>

  <!-- Timeline -->
  <div class="timeline">
  <?php if (empty($items)): ?>
    <div class="empty">Aucun titre programmé pour ce jour.<br>Ajoutez des passages depuis le panneau latéral.</div>
  <?php else: ?>
    <?php foreach ($items as $i => $item): ?>
    <div class="prog-item">
      <div class="prog-order"><?= str_pad($item['ordre'], 2, '0', STR_PAD_LEFT) ?></div>
      <div class="prog-time"><?= calcPassage($items, $i) ?></div>
      <div class="prog-info">
        <div class="prog-title"><?= h($item['nom_titre']) ?></div>
        <div class="prog-meta">
          <?php if ($item['nom_genre']): ?>
          <span class="badge"><?= h($item['nom_genre']) ?></span>
          <?php endif; ?>
          <?= h($item['nom_artiste']) ?> &nbsp;·&nbsp;
          <span style="color:var(--muted)"><?= fmtDuree((int)$item['duree']) ?></span>
        </div>
      </div>
      <div class="prog-actions">
        <?php if ($i > 0): ?>
        <form method="post">
          <input type="hidden" name="action" value="move_up">
          <input type="hidden" name="id_prog" value="<?= $item['id_prog'] ?>">
          <input type="hidden" name="ordre" value="<?= $item['ordre'] ?>">
          <input type="hidden" name="date_prog" value="<?= $date_sel ?>">
          <button type="submit" class="btn btn-move" title="Monter">▲</button>
        </form>
        <?php endif; ?>
        <?php if ($i < count($items) - 1): ?>
        <form method="post">
          <input type="hidden" name="action" value="move_down">
          <input type="hidden" name="id_prog" value="<?= $item['id_prog'] ?>">
          <input type="hidden" name="ordre" value="<?= $item['ordre'] ?>">
          <input type="hidden" name="date_prog" value="<?= $date_sel ?>">
          <button type="submit" class="btn btn-move" title="Descendre">▼</button>
        </form>
        <?php endif; ?>
        <form method="post" onsubmit="return confirm('Retirer ce passage ?')">
          <input type="hidden" name="action" value="del_prog">
          <input type="hidden" name="id_prog" value="<?= $item['id_prog'] ?>">
          <input type="hidden" name="date_prog" value="<?= $date_sel ?>">
          <button type="submit" class="btn btn-danger">✕</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
  </div>
</div>

<!-- ══ SIDEBAR ══ -->
<div class="sidebar">
<div class="panel" style="border-top-color: var(--ok);">
    <div class="panel-title" style="color: var(--ok);">🤖 Remplissage Auto</div>
    <p style="font-size: 0.75rem; color: var(--muted); margin-bottom: 1rem; font-family: var(--mono);">
      Génère instantanément 300 morceaux aléatoires (incluant les nouveautés du Shell) en respectant strictement les règles de rotation.
    </p>
    <form method="post">
      <input type="hidden" name="action" value="generate_auto">
      <input type="hidden" name="date_prog" value="<?= $date_sel ?>">
      <button type="submit" class="btn" style="width:100%; background: var(--ok); color: #000;">
        ⚡ Générer 300 morceaux
      </button>
    </form>
  </div>
  <!-- Ajouter un passage -->
  <div class="panel">
    <div class="panel-title">➕ Ajouter un passage</div>
    <form method="post">
      <input type="hidden" name="action" value="add_prog">
      <input type="hidden" name="date_prog" value="<?= $date_sel ?>">
      <div class="field">
        <label>Titre *</label>
        <select name="id_titre" required>
          <option value="">— Sélectionner un titre —</option>
          <?php foreach ($titres as $t): ?>
          <option value="<?= $t['id_titre'] ?>">
            <?= h($t['nom_artiste']) ?> – <?= h($t['nom_titre']) ?>
            (<?= fmtDuree((int)$t['duree']) ?>)
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-accent" style="width:100%">Ajouter en fin de liste</button>
    </form>
  </div>

  <!-- Copier une journée -->
  <div class="panel copy-panel">
    <div class="panel-title">📋 Copier une journée</div>
    <form method="post" onsubmit="return confirm('Écraser la programmation cible ?')">
      <input type="hidden" name="action" value="copy_day">
      <div class="field">
        <label>Date source</label>
        <input type="date" name="date_src" value="<?= $date_sel ?>">
      </div>
      <div class="field">
        <label>Date destination</label>
        <input type="date" name="date_dst" value="<?= $next_date ?>">
      </div>
      <button type="submit" class="btn btn-accent" style="width:100%;background:var(--accent);">Copier la programmation</button>
    </form>
  </div>

</div><!-- /sidebar -->
</div><!-- /main -->

</body>
</html>
