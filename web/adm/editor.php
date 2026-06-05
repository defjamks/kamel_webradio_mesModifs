<?php
// ============================================================
//  WebRadio – Éditeur : Titres / Artistes / Genres
// ============================================================
require_once __DIR__ . '/config.php';

// ── Helpers ──────────────────────────────────────────────────
function flash(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}
function getFlash(): ?array {
    if (!isset($_SESSION['flash'])) return null;
    $f = $_SESSION['flash']; unset($_SESSION['flash']); return $f;
}
session_start();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$tab    = $_GET['tab']    ?? 'titres';

// ════════════════════════════════════════════════════════════
//  ACTIONS POST (CRUD)
// ════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo = db();
        switch ($action) {

            // ── GENRE ─────────────────────────────────────────
            case 'add_genre':
                $nom = trim($_POST['nom_genre'] ?? '');
                if ($nom === '') throw new Exception("Nom de genre vide.");
                $pdo->prepare("INSERT INTO genre (nom_genre) VALUES (?)")->execute([$nom]);
                flash('ok', "Genre « $nom » ajouté.");
                break;

            case 'edit_genre':
                $id  = (int)($_POST['id_genre'] ?? 0);
                $nom = trim($_POST['nom_genre'] ?? '');
                if (!$id || $nom === '') throw new Exception("Données invalides.");
                $pdo->prepare("UPDATE genre SET nom_genre=? WHERE id_genre=?")->execute([$nom, $id]);
                flash('ok', "Genre mis à jour.");
                break;

            case 'del_genre':
                $id = (int)($_POST['id_genre'] ?? 0);
                $pdo->prepare("DELETE FROM genre WHERE id_genre=?")->execute([$id]);
                flash('ok', "Genre supprimé.");
                break;

            // ── ARTISTE ───────────────────────────────────────
            case 'add_artiste':
                $nom = trim($_POST['nom_artiste'] ?? '');
                if ($nom === '') throw new Exception("Nom d'artiste vide.");
                $pdo->prepare("INSERT INTO artiste (nom_artiste) VALUES (?)")->execute([$nom]);
                flash('ok', "Artiste « $nom » ajouté.");
                break;

            case 'edit_artiste':
                $id  = (int)($_POST['id_artiste'] ?? 0);
                $nom = trim($_POST['nom_artiste'] ?? '');
                if (!$id || $nom === '') throw new Exception("Données invalides.");
                $pdo->prepare("UPDATE artiste SET nom_artiste=? WHERE id_artiste=?")->execute([$nom, $id]);
                flash('ok', "Artiste mis à jour.");
                break;

            case 'del_artiste':
                $id = (int)($_POST['id_artiste'] ?? 0);
                $pdo->prepare("DELETE FROM artiste WHERE id_artiste=?")->execute([$id]);
                flash('ok', "Artiste supprimé.");
                break;

            // ── TITRE ─────────────────────────────────────────
            case 'add_titre':
                $nom       = trim($_POST['nom_titre']  ?? '');
                $chemin    = trim($_POST['chemin']      ?? '');
                $id_art    = (int)($_POST['id_artiste'] ?? 0);
                $id_genre  = ($_POST['id_genre'] ?? '') !== '' ? (int)$_POST['id_genre'] : null;
                $duree     = (int)($_POST['duree']      ?? 0);
                if ($nom === '' || $chemin === '' || !$id_art)
                    throw new Exception("Champs obligatoires manquants.");
                $pdo->prepare("INSERT INTO titre (nom_titre,chemin,id_artiste,id_genre,duree)
                               VALUES (?,?,?,?,?)")
                    ->execute([$nom, $chemin, $id_art, $id_genre, $duree]);
                flash('ok', "Titre « $nom » ajouté.");
                break;

            case 'edit_titre':
                $id       = (int)($_POST['id_titre']   ?? 0);
                $nom      = trim($_POST['nom_titre']   ?? '');
                $chemin   = trim($_POST['chemin']       ?? '');
                $id_art   = (int)($_POST['id_artiste']  ?? 0);
                $id_genre = ($_POST['id_genre'] ?? '') !== '' ? (int)$_POST['id_genre'] : null;
                $duree    = (int)($_POST['duree']       ?? 0);
                if (!$id || $nom === '' || $chemin === '' || !$id_art)
                    throw new Exception("Données invalides.");
                $pdo->prepare("UPDATE titre SET nom_titre=?,chemin=?,id_artiste=?,id_genre=?,duree=?
                               WHERE id_titre=?")
                    ->execute([$nom, $chemin, $id_art, $id_genre, $duree, $id]);
                flash('ok', "Titre mis à jour.");
                break;

            case 'del_titre':
                $id = (int)($_POST['id_titre'] ?? 0);
                $pdo->prepare("DELETE FROM titre WHERE id_titre=?")->execute([$id]);
                flash('ok', "Titre supprimé.");
                break;

            default:
                flash('err', "Action inconnue.");
        }
    } catch (Exception $e) {
        flash('err', "Erreur : " . $e->getMessage());
    }

    $redirect_tab = match(true) {
        str_contains($action, 'genre')   => 'genres',
        str_contains($action, 'artiste') => 'artistes',
        default                          => 'titres',
    };
    header("Location: editor.php?tab=$redirect_tab");
    exit;
}

// ════════════════════════════════════════════════════════════
//  LECTURE DES DONNÉES
// ════════════════════════════════════════════════════════════
$pdo      = db();
$genres   = $pdo->query("SELECT * FROM genre   ORDER BY nom_genre")->fetchAll();
$artistes = $pdo->query("SELECT * FROM artiste ORDER BY nom_artiste")->fetchAll();
$titres   = $pdo->query("
    SELECT t.*, a.nom_artiste, g.nom_genre
    FROM titre t
    JOIN artiste a ON a.id_artiste = t.id_artiste
    LEFT JOIN genre g ON g.id_genre = t.id_genre
    ORDER BY a.nom_artiste, t.nom_titre
")->fetchAll();

$flash = getFlash();

// ── Durée formatée ───────────────────────────────────────────
function fmtDuree(int $s): string {
    return sprintf('%d:%02d', intdiv($s, 60), $s % 60);
}

// ── Sécurité HTML ─────────────────────────────────────────────
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WebRadio – Éditeur</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;800&family=Space+Mono:ital@0;1&display=swap" rel="stylesheet">
<style>
/* ─────────────────────────────────────────────
   DESIGN SYSTEM : Brutalisme Électro
───────────────────────────────────────────── */
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

body {
  background: var(--bg);
  color: var(--text);
  font-family: var(--sans);
  min-height: 100vh;
}

/* ── Header ── */
header {
  display: flex;
  align-items: center;
  gap: 1.5rem;
  padding: 1.2rem 2rem;
  background: var(--surface);
  border-bottom: 2px solid var(--accent);
}
.logo {
  font-size: 1.6rem;
  font-weight: 800;
  letter-spacing: -0.03em;
  color: var(--accent);
  text-decoration: none;
}
.logo span { color: var(--accent2); }
.header-links { margin-left: auto; display: flex; gap: 1rem; }
.header-links a {
  color: var(--muted);
  text-decoration: none;
  font-size: .85rem;
  font-family: var(--mono);
  transition: color .2s;
}
.header-links a:hover { color: var(--accent); }

/* ── Flash ── */
.flash {
  margin: 1rem 2rem 0;
  padding: .75rem 1rem;
  border-left: 4px solid;
  font-family: var(--mono);
  font-size: .85rem;
  border-radius: var(--radius);
}
.flash.ok  { border-color: var(--ok);  background: #47ff9a18; color: var(--ok);  }
.flash.err { border-color: var(--danger); background: #ff4f4f18; color: var(--danger); }

/* ── Tabs ── */
.tabs {
  display: flex;
  gap: 0;
  padding: 1.5rem 2rem 0;
  border-bottom: 2px solid var(--border);
}
.tab-btn {
  background: none;
  border: none;
  padding: .7rem 1.5rem;
  color: var(--muted);
  font-family: var(--sans);
  font-size: .9rem;
  font-weight: 600;
  cursor: pointer;
  border-bottom: 2px solid transparent;
  margin-bottom: -2px;
  transition: all .2s;
  text-transform: uppercase;
  letter-spacing: .08em;
}
.tab-btn:hover { color: var(--text); }
.tab-btn.active { color: var(--accent); border-color: var(--accent); }

/* ── Tab panels ── */
.tab-panel { display: none; padding: 2rem; }
.tab-panel.active { display: block; }

/* ── Section header ── */
.section-title {
  font-size: 1.1rem;
  font-weight: 800;
  letter-spacing: .05em;
  text-transform: uppercase;
  color: var(--accent3);
  margin-bottom: 1.2rem;
  display: flex;
  align-items: center;
  gap: .6rem;
}
.section-title::after {
  content: '';
  flex: 1;
  height: 1px;
  background: var(--border);
}

/* ── Add form card ── */
.add-card {
  background: var(--card);
  border: 1px solid var(--border);
  border-top: 3px solid var(--accent);
  border-radius: var(--radius);
  padding: 1.2rem 1.5rem;
  margin-bottom: 2rem;
}
.add-card form { display: flex; flex-wrap: wrap; gap: .75rem; align-items: flex-end; }
.field { display: flex; flex-direction: column; gap: .3rem; min-width: 180px; flex: 1; }
.field label {
  font-size: .72rem;
  font-family: var(--mono);
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: .08em;
}
.field input, .field select {
  background: var(--surface);
  border: 1px solid var(--border);
  color: var(--text);
  padding: .5rem .75rem;
  border-radius: var(--radius);
  font-family: var(--mono);
  font-size: .85rem;
  outline: none;
  transition: border-color .2s;
}
.field input:focus, .field select:focus { border-color: var(--accent); }
.field select option { background: var(--surface); }

/* ── Buttons ── */
.btn {
  padding: .5rem 1.2rem;
  border: none;
  border-radius: var(--radius);
  font-family: var(--sans);
  font-weight: 600;
  font-size: .85rem;
  cursor: pointer;
  transition: opacity .15s, transform .1s;
  white-space: nowrap;
}
.btn:hover { opacity: .85; }
.btn:active { transform: scale(.97); }
.btn-accent  { background: var(--accent);  color: #000; }
.btn-danger  { background: transparent; color: var(--danger); border: 1px solid var(--danger); font-size: .75rem; padding: .3rem .7rem; }
.btn-edit    { background: transparent; color: var(--accent3); border: 1px solid var(--accent3); font-size: .75rem; padding: .3rem .7rem; }
.btn-save    { background: var(--ok); color: #000; font-size: .75rem; padding: .3rem .7rem; }

/* ── Data table ── */
.data-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
.data-table th {
  text-align: left;
  padding: .6rem .9rem;
  font-family: var(--mono);
  font-size: .7rem;
  text-transform: uppercase;
  letter-spacing: .1em;
  color: var(--muted);
  border-bottom: 1px solid var(--border);
  background: var(--surface);
}
.data-table td {
  padding: .55rem .9rem;
  border-bottom: 1px solid #1e1e2d;
  vertical-align: middle;
}
.data-table tr:hover td { background: #16162200; background: rgba(232,255,71,.03); }
.data-table .mono { font-family: var(--mono); font-size: .8rem; color: var(--muted); }
.data-table .badge {
  display: inline-block;
  padding: .15rem .5rem;
  border-radius: 2px;
  font-size: .72rem;
  font-family: var(--mono);
  background: var(--surface);
  border: 1px solid var(--border);
  color: var(--muted);
}
.actions { display: flex; gap: .4rem; }

/* ── Inline edit row ── */
.edit-row td { background: rgba(71,197,255,.05) !important; }
.edit-row input, .edit-row select {
  background: var(--surface);
  border: 1px solid var(--accent3);
  color: var(--text);
  padding: .3rem .5rem;
  border-radius: var(--radius);
  font-family: var(--mono);
  font-size: .82rem;
  width: 100%;
}

/* ── Empty state ── */
.empty {
  text-align: center;
  padding: 3rem;
  color: var(--muted);
  font-family: var(--mono);
  font-size: .85rem;
}

/* ── Scrollable wrapper ── */
.table-wrap { overflow-x: auto; }
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

<div class="tabs">
  <?php foreach (['titres' => 'Titres', 'artistes' => 'Artistes', 'genres' => 'Genres'] as $k => $label): ?>
  <button class="tab-btn <?= $tab === $k ? 'active' : '' ?>"
          onclick="setTab('<?= $k ?>')"><?= $label ?></button>
  <?php endforeach; ?>
</div>

<!-- ══════════════ TITRES ══════════════ -->
<div id="tab-titres" class="tab-panel <?= $tab === 'titres' ? 'active' : '' ?>">
  <p class="section-title">Ajouter un titre</p>
  <div class="add-card">
    <form method="post" action="editor.php">
      <input type="hidden" name="action" value="add_titre">
      <div class="field">
        <label>Nom du titre *</label>
        <input type="text" name="nom_titre" required placeholder="My Song">
      </div>
      <div class="field" style="flex:2; min-width:260px;">
        <label>Chemin fichier *</label>
        <input type="text" name="chemin" required placeholder="/var/lib/webradio/music/...">
      </div>
      <div class="field">
        <label>Artiste *</label>
        <select name="id_artiste" required>
          <option value="">— Sélectionner —</option>
          <?php foreach ($artistes as $a): ?>
          <option value="<?= $a['id_artiste'] ?>"><?= h($a['nom_artiste']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Genre</label>
        <select name="id_genre">
          <option value="">— Aucun —</option>
          <?php foreach ($genres as $g): ?>
          <option value="<?= $g['id_genre'] ?>"><?= h($g['nom_genre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field" style="max-width:120px;">
        <label>Durée (s)</label>
        <input type="number" name="duree" value="0" min="0">
      </div>
      <button type="submit" class="btn btn-accent">+ Ajouter</button>
    </form>
  </div>

  <p class="section-title">Bibliothèque (<?= count($titres) ?> titres)</p>
  <div class="table-wrap">
  <?php if (empty($titres)): ?>
    <div class="empty">Aucun titre enregistré.</div>
  <?php else: ?>
  <table class="data-table" id="tbl-titres">
    <thead>
      <tr>
        <th>#</th><th>Titre</th><th>Artiste</th><th>Genre</th>
        <th>Durée</th><th>Diffusions</th><th>dernier passage</th><th>Chemin</th><th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($titres as $t): ?>
  <tr id="row-t-<?= $t['id_titre'] ?>">
  <td class="mono"><?= $t['id_titre'] ?></td>
  <td><strong><?= h($t['nom_titre']) ?></strong></td>
  <td><?= h($t['nom_artiste']) ?></td>
  <td><?= $t['nom_genre'] ? '<span class="badge">'.h($t['nom_genre']).'</span>' : '<span class="mono">—</span>' ?></td>
  <td class="mono"><?= fmtDuree((int)$t['duree']) ?></td>
  
  <td class="mono" style="color: var(--accent); font-weight: bold; text-align: center;"><?= (int)$t['compteur_diffusion'] ?>x</td>
  
  <td class="mono" style="font-size: 0.75rem;">
    <?= $t['derniere_diffusion'] ? date('d/m H:i', strtotime($t['derniere_diffusion'])) : '<span style="color:var(--muted)">Jamais</span>' ?>
  </td>
  
  <td class="mono" style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= h($t['chemin']) ?>"><?= h($t['chemin']) ?></td>
  <td class="actions">
    <button class="btn btn-edit" onclick="editTitre(<?= $t['id_titre'] ?>)">Éditer</button>
    <form method="post" onsubmit="return confirm('Supprimer ce titre ?')">
      <input type="hidden" name="action" value="del_titre">
      <input type="hidden" name="id_titre" value="<?= $t['id_titre'] ?>">
      <button type="submit" class="btn btn-danger">✕</button>
    </form>
  </td>
</tr>

<tr id="edit-t-<?= $t['id_titre'] ?>" class="edit-row" style="display:none;">
  <td colspan="9"> <form method="post" action="editor.php" style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:flex-end;padding:.5rem 0;">
          <input type="hidden" name="action" value="edit_titre">
          <input type="hidden" name="id_titre" value="<?= $t['id_titre'] ?>">
          <div class="field">
            <label>Nom</label>
            <input type="text" name="nom_titre" value="<?= h($t['nom_titre']) ?>" required>
          </div>
          <div class="field" style="flex:2;min-width:240px;">
            <label>Chemin</label>
            <input type="text" name="chemin" value="<?= h($t['chemin']) ?>" required>
          </div>
          <div class="field">
            <label>Artiste</label>
            <select name="id_artiste" required>
              <?php foreach ($artistes as $a): ?>
              <option value="<?= $a['id_artiste'] ?>" <?= $a['id_artiste'] == $t['id_artiste'] ? 'selected' : '' ?>>
                <?= h($a['nom_artiste']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>Genre</label>
            <select name="id_genre">
              <option value="">— Aucun —</option>
              <?php foreach ($genres as $g): ?>
              <option value="<?= $g['id_genre'] ?>" <?= $g['id_genre'] == $t['id_genre'] ? 'selected' : '' ?>>
                <?= h($g['nom_genre']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field" style="max-width:110px;">
            <label>Durée (s)</label>
            <input type="number" name="duree" value="<?= (int)$t['duree'] ?>" min="0">
          </div>
          <button type="submit" class="btn btn-save">✔ Sauver</button>
          <button type="button" class="btn btn-danger" onclick="cancelEdit('t',<?= $t['id_titre'] ?>)">Annuler</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  </div>
</div>

<!-- ══════════════ ARTISTES ══════════════ -->
<div id="tab-artistes" class="tab-panel <?= $tab === 'artistes' ? 'active' : '' ?>">
  <p class="section-title">Ajouter un artiste</p>
  <div class="add-card">
    <form method="post" action="editor.php">
      <input type="hidden" name="action" value="add_artiste">
      <div class="field">
        <label>Nom de l'artiste *</label>
        <input type="text" name="nom_artiste" required placeholder="Daft Punk">
      </div>
      <button type="submit" class="btn btn-accent">+ Ajouter</button>
    </form>
  </div>

  <p class="section-title">Artistes (<?= count($artistes) ?>)</p>
  <div class="table-wrap">
  <?php if (empty($artistes)): ?>
    <div class="empty">Aucun artiste enregistré.</div>
  <?php else: ?>
  <table class="data-table">
    <thead><tr><th>#</th><th>Nom</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($artistes as $a): ?>
    <tr id="row-a-<?= $a['id_artiste'] ?>">
      <td class="mono"><?= $a['id_artiste'] ?></td>
      <td><strong><?= h($a['nom_artiste']) ?></strong></td>
      <td class="actions">
        <button class="btn btn-edit" onclick="editArtiste(<?= $a['id_artiste'] ?>)">Éditer</button>
        <form method="post" onsubmit="return confirm('Supprimer cet artiste ?')">
          <input type="hidden" name="action" value="del_artiste">
          <input type="hidden" name="id_artiste" value="<?= $a['id_artiste'] ?>">
          <button type="submit" class="btn btn-danger">✕</button>
        </form>
      </td>
    </tr>
    <tr id="edit-a-<?= $a['id_artiste'] ?>" class="edit-row" style="display:none;">
      <td colspan="3">
        <form method="post" action="editor.php" style="display:flex;gap:.5rem;align-items:flex-end;">
          <input type="hidden" name="action" value="edit_artiste">
          <input type="hidden" name="id_artiste" value="<?= $a['id_artiste'] ?>">
          <div class="field" style="flex:1;">
            <label>Nom</label>
            <input type="text" name="nom_artiste" value="<?= h($a['nom_artiste']) ?>" required>
          </div>
          <button type="submit" class="btn btn-save">✔ Sauver</button>
          <button type="button" class="btn btn-danger" onclick="cancelEdit('a',<?= $a['id_artiste'] ?>)">Annuler</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  </div>
</div>

<!-- ══════════════ GENRES ══════════════ -->
<div id="tab-genres" class="tab-panel <?= $tab === 'genres' ? 'active' : '' ?>">
  <p class="section-title">Ajouter un genre</p>
  <div class="add-card">
    <form method="post" action="editor.php">
      <input type="hidden" name="action" value="add_genre">
      <div class="field">
        <label>Nom du genre *</label>
        <input type="text" name="nom_genre" required placeholder="Électro, Jazz, Rock…">
      </div>
      <button type="submit" class="btn btn-accent">+ Ajouter</button>
    </form>
  </div>

  <p class="section-title">Genres (<?= count($genres) ?>)</p>
  <div class="table-wrap">
  <?php if (empty($genres)): ?>
    <div class="empty">Aucun genre enregistré.</div>
  <?php else: ?>
  <table class="data-table">
    <thead><tr><th>#</th><th>Nom</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($genres as $g): ?>
    <tr id="row-g-<?= $g['id_genre'] ?>">
      <td class="mono"><?= $g['id_genre'] ?></td>
      <td><strong><?= h($g['nom_genre']) ?></strong></td>
      <td class="actions">
        <button class="btn btn-edit" onclick="editGenre(<?= $g['id_genre'] ?>)">Éditer</button>
        <form method="post" onsubmit="return confirm('Supprimer ce genre ?')">
          <input type="hidden" name="action" value="del_genre">
          <input type="hidden" name="id_genre" value="<?= $g['id_genre'] ?>">
          <button type="submit" class="btn btn-danger">✕</button>
        </form>
      </td>
    </tr>
    <tr id="edit-g-<?= $g['id_genre'] ?>" class="edit-row" style="display:none;">
      <td colspan="3">
        <form method="post" action="editor.php" style="display:flex;gap:.5rem;align-items:flex-end;">
          <input type="hidden" name="action" value="edit_genre">
          <input type="hidden" name="id_genre" value="<?= $g['id_genre'] ?>">
          <div class="field" style="flex:1;">
            <label>Nom</label>
            <input type="text" name="nom_genre" value="<?= h($g['nom_genre']) ?>" required>
          </div>
          <button type="submit" class="btn btn-save">✔ Sauver</button>
          <button type="button" class="btn btn-danger" onclick="cancelEdit('g',<?= $g['id_genre'] ?>)">Annuler</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  </div>
</div>

<script>
function setTab(name) {
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  document.querySelector(`[onclick="setTab('${name}')"]`).classList.add('active');
  history.replaceState(null, '', '?tab=' + name);
}

function showEdit(prefix, id) {
  document.getElementById('row-'   + prefix + '-' + id).style.display = 'none';
  document.getElementById('edit-'  + prefix + '-' + id).style.display = 'table-row';
}
function cancelEdit(prefix, id) {
  document.getElementById('row-'   + prefix + '-' + id).style.display = 'table-row';
  document.getElementById('edit-'  + prefix + '-' + id).style.display = 'none';
}

function editTitre(id)   { showEdit('t', id); }
function editArtiste(id) { showEdit('a', id); }
function editGenre(id)   { showEdit('g', id); }
</script>
</body>
</html>
