#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
============================================================
  WebRadio - Import automatique de fichiers musicaux
============================================================
  Scrute un répertoire à la recherche de fichiers audio dont
  le nom suit le pattern :
      <nom_artiste>__<nom_titre>.<extension>

  Pour chaque fichier trouvé :
    - Insère l'artiste s'il n'existe pas
    - Insère le titre  s'il n'existe pas
    - Extrait la durée via mutagen (si disponible)

  Utilisation :
      python3 02_import_music.py [--dir /chemin/musique] [--ext mp3,flac,ogg]

  Pré-requis :
      pip install mysql-connector-python mutagen
============================================================
"""

import os
import re
import sys
import argparse
import logging

# ── Dépendances optionnelles ────────────────────────────────
try:
    import mysql.connector
    from mysql.connector import Error as MySQLError
except ImportError:
    sys.exit("❌  Installez mysql-connector-python : pip install mysql-connector-python")

try:
    from mutagen import File as MutagenFile
    MUTAGEN_OK = True
except ImportError:
    MUTAGEN_OK = False
    print("⚠️  mutagen non disponible – la durée sera enregistrée à 0. "
          "Installez-le avec : pip install mutagen")

# ── Logging ─────────────────────────────────────────────────
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
log = logging.getLogger("webradio-import")

# ── Configuration DB (à adapter ou passer en variables d'env) ─
DB_CONFIG = {
    "host":     os.getenv("DB_HOST",   "localhost"),
    "port":     int(os.getenv("DB_PORT", "3306")),
    "user":     os.getenv("DB_USER",   "webradio_user"),
    "password": os.getenv("DB_PASS",   "ChangeMe!"),
    "database": os.getenv("DB_NAME",   "webradio"),
    "charset":  "utf8mb4",
}

# ── Extensions audio supportées par défaut ──────────────────
DEFAULT_EXTENSIONS = {"mp3", "flac", "ogg", "wav", "aac", "m4a", "opus", "wma"}

# ── Pattern attendu : nom_artiste__nom_titre.ext ────────────
FILENAME_PATTERN = re.compile(
    r"^(?P<artiste>.+?)__(?P<titre>.+?)\.(?P<ext>[^.]+)$",
    re.IGNORECASE,
)


# ════════════════════════════════════════════════════════════
def get_duration(filepath: str) -> int:
    """Retourne la durée en secondes (0 si indéterminable)."""
    if not MUTAGEN_OK:
        return 0
    try:
        audio = MutagenFile(filepath)
        if audio and audio.info:
            return int(audio.info.length)
    except Exception as exc:
        log.debug("Durée non récupérable pour %s : %s", filepath, exc)
    return 0


def get_or_create_artiste(cursor, nom: str) -> int:
    """Insère l'artiste si absent et retourne son id."""
    cursor.execute(
        "SELECT id_artiste FROM artiste WHERE nom_artiste = %s", (nom,)
    )
    row = cursor.fetchone()
    if row:
        return row[0]
    cursor.execute(
        "INSERT INTO artiste (nom_artiste) VALUES (%s)", (nom,)
    )
    log.info("  ✚ Nouvel artiste : %s", nom)
    return cursor.lastrowid


def insert_titre_if_absent(cursor, nom_titre: str, chemin: str,
                           id_artiste: int, duree: int) -> bool:
    """Insère le titre si le chemin n'existe pas encore. Retourne True si inséré."""
    cursor.execute(
        "SELECT id_titre FROM titre WHERE chemin = %s", (chemin,)
    )
    if cursor.fetchone():
        return False  # déjà présent
    cursor.execute(
        """
        INSERT INTO titre (nom_titre, chemin, id_artiste, id_genre, duree)
        VALUES (%s, %s, %s, NULL, %s)
        """,
        (nom_titre, chemin, id_artiste, duree),
    )
    log.info("  ✚ Nouveau titre : %s  (durée : %ds)", nom_titre, duree)
    return True


# ════════════════════════════════════════════════════════════
def scan_directory(music_dir: str, extensions: set, conn) -> dict:
    """Parcourt récursivement le répertoire et importe les fichiers."""
    stats = {"found": 0, "skipped_pattern": 0, "skipped_ext": 0,
             "inserted": 0, "already_present": 0, "errors": 0}

    cursor = conn.cursor()

    for root, _dirs, files in os.walk(music_dir):
        for filename in sorted(files):
            filepath = os.path.join(root, filename)
            m = FILENAME_PATTERN.match(filename)

            if not m:
                log.debug("Pattern non reconnu : %s", filename)
                stats["skipped_pattern"] += 1
                continue

            ext = m.group("ext").lower()
            if ext not in extensions:
                log.debug("Extension ignorée : %s", filename)
                stats["skipped_ext"] += 1
                continue

            stats["found"] += 1
            nom_artiste = m.group("artiste").strip().replace("_", " ")
            nom_titre   = m.group("titre").strip().replace("_", " ")

            log.info("→ %s  |  Artiste : %s  |  Titre : %s",
                     filename, nom_artiste, nom_titre)

            try:
                duree      = get_duration(filepath)
                id_artiste = get_or_create_artiste(cursor, nom_artiste)
                inserted   = insert_titre_if_absent(
                    cursor, nom_titre, filepath, id_artiste, duree
                )
                conn.commit()
                if inserted:
                    stats["inserted"] += 1
                else:
                    log.info("  ↩ Déjà présent : %s", filepath)
                    stats["already_present"] += 1
            except MySQLError as exc:
                conn.rollback()
                log.error("  ✖ Erreur DB pour %s : %s", filename, exc)
                stats["errors"] += 1

    cursor.close()
    return stats


# ════════════════════════════════════════════════════════════
def main():
    parser = argparse.ArgumentParser(
        description="Import de fichiers musicaux dans la base webradio."
    )
    parser.add_argument(
        "--dir", "-d",
        default=os.getenv("MUSIC_DIR", "/var/lib/webradio/music"),
        help="Répertoire racine des fichiers audio (défaut : /var/lib/webradio/music)",
    )
    parser.add_argument(
        "--ext", "-e",
        default=",".join(sorted(DEFAULT_EXTENSIONS)),
        help="Extensions acceptées, séparées par des virgules (défaut : mp3,flac,ogg…)",
    )
    parser.add_argument(
        "--dry-run", action="store_true",
        help="Simulation : affiche ce qui serait importé sans écrire en base",
    )
    args = parser.parse_args()

    music_dir  = os.path.abspath(args.dir)
    extensions = {e.strip().lower().lstrip(".") for e in args.ext.split(",")}

    if not os.path.isdir(music_dir):
        sys.exit(f"❌  Répertoire introuvable : {music_dir}")

    log.info("═══════════════════════════════════════")
    log.info("  WebRadio – Import musical")
    log.info("  Répertoire : %s", music_dir)
    log.info("  Extensions : %s", ", ".join(sorted(extensions)))
    if args.dry_run:
        log.info("  MODE DRY-RUN (aucune écriture)")
    log.info("═══════════════════════════════════════")

    if args.dry_run:
        # Juste afficher les fichiers reconnus
        count = 0
        for root, _dirs, files in os.walk(music_dir):
            for filename in sorted(files):
                m = FILENAME_PATTERN.match(filename)
                if m and m.group("ext").lower() in extensions:
                    count += 1
                    print(f"  [{count:4d}] {filename}")
        print(f"\nTotal : {count} fichier(s) seraient importés.")
        return

    # ── Connexion MariaDB ────────────────────────────────────
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        log.info("✔  Connecté à MariaDB (%s:%s/%s)",
                 DB_CONFIG["host"], DB_CONFIG["port"], DB_CONFIG["database"])
    except MySQLError as exc:
        sys.exit(f"❌  Connexion impossible : {exc}")

    try:
        stats = scan_directory(music_dir, extensions, conn)
    finally:
        conn.close()

    # ── Résumé ──────────────────────────────────────────────
    log.info("═══════════════════════════════════════")
    log.info("  Résumé de l'import")
    log.info("  Fichiers reconnus    : %d", stats["found"])
    log.info("  Titres insérés       : %d", stats["inserted"])
    log.info("  Déjà présents        : %d", stats["already_present"])
    log.info("  Pattern non reconnu  : %d", stats["skipped_pattern"])
    log.info("  Extension ignorée    : %d", stats["skipped_ext"])
    log.info("  Erreurs              : %d", stats["errors"])
    log.info("═══════════════════════════════════════")


if __name__ == "__main__":
    main()
