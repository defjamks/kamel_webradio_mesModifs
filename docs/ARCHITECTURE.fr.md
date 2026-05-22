# Architecture de la web radio

## Vue d'ensemble

La solution repose sur cinq composants qui s'enchaînent en pipeline. Chaque composant a une responsabilité unique et communique avec les autres via des interfaces bien définies (RTMP local, webhooks HTTP, pipe PCM, système de fichiers HLS).

```
 OBS Studio / Mixxx
        │  RTMP (WAN ou LAN)
        ▼
 ┌─────────────────────────────────────────┐
 │              nginx-rtmp                 │
 │   exec_publish    →  on_publish.sh      │
 │   exec_publish_done → on_unpublish.sh   │
 └──────────┬──────────────────────────────┘
            │ webhook HTTP POST + flag fichier
            ▼
 ┌─────────────────────────────────────────┐
 │          Superviseur Python             │
 │  • watchdog FFmpeg                      │
 │  • basculement live ↔ fallback          │
 │  • lecture programmation MariaDB        │
 │  • injection métadonnées ID3 (ts_inject)│
 └──────────┬──────────────────────────────┘
            │ commandes subprocess (PCM pipe ou RTMP)
            ▼
 ┌─────────────────────────────────────────┐
 │           FFmpeg HLS                    │
 │  génère segments .ts + playlist .m3u8   │
 │  dans /opt/webradio/hls/                │
 └──────────┬──────────────────────────────┘
            │ fichiers statiques HTTP
            ▼
 ┌─────────────────────────────────────────┐
 │     nginx (serveur HTTP :80 or :443)    │
 │  sert /opt/webradio/hls/ au CDN/player  │
 └─────────────────────────────────────────┘
```

L'administrateur interagit via un outil externe (Mixxx, script, interface web) qui alimente la base **MariaDB** (`webradio`) avec les titres et la programmation journalière. Le superviseur consulte cette base au démarrage et à minuit pour construire la file de lecture.

---

## Composants détaillés

### nginx-rtmp

nginx est compilé ou packagé avec le module `nginx-rtmp`. Il joue deux rôles simultanés :

- **Ingest RTMP** (port 1935) : reçoit le flux audio d'OBS Studio ou de Mixxx. Dès qu'un diffuseur connecte ou déconnecte, nginx-rtmp exécute les scripts shell `on_publish.sh` et `on_unpublish.sh` via les directives `exec_publish` et `exec_publish_done`.
- **Serveur HTTP**: sert le dossier `/opt/webradio/hls/` en HTTP statique pour le CDN (mode pull) ou directement pour les players HLS.


### Scripts shell (on_publish.sh / on_unpublish.sh)

Ces scripts constituent la **double signalisation** vers le superviseur :

1. Ils créent ou suppriment le fichier flag `/tmp/webradio_live.flag` — surveillé par le superviseur par polling toutes les 2 secondes indépendamment du réseau.
2. Ils envoient un `POST` HTTP vers `http://127.0.0.1:8089/on_publish` ou `/on_unpublish` — chemin réactif (instantané).

### Superviseur Python

Cœur du système. Processus unique tournant en continu, il orchestre tous les autres composants.

**Trois sources de détection d'état OBS :**

| Source | Mécanisme | Latence |
|---|---|---|
| Webhook HTTP | POST de on_publish.sh | < 100 ms |
| Flag fichier | Poll toutes les 2s | ≤ 2 s |
| API stat nginx-rtmp | Poll XML toutes les 10s | ≤ 10 s |

Les trois sont actives simultanément. Les sources 2 et 3 servent de filet de sécurité si un webhook est manqué (redémarrage du superviseur au mauvais moment, erreur réseau locale).

**Délai de grâce** : 8 secondes s'écoulent après un `on_unpublish` avant de basculer en fallback. Cela absorbe les micro-coupures OBS (relance de scène, changement de profil) sans interrompre le flux HLS.

**Watchdog FFmpeg** : toutes les 5 secondes, le superviseur vérifie que le process FFmpeg HLS tourne. En cas de crash inattendu, il le redémarre automatiquement dans le bon mode (live ou fallback).

### FFmpeg HLS

FFmpeg est piloté différemment selon le mode actif :

**Mode LIVE** : FFmpeg lit le flux RTMP local (`rtmp://127.0.0.1/live/stream`) et le transcode directement en segments HLS AAC-LC.

**Mode FALLBACK** : le superviseur lance une `AudioPipe` — un thread Python décode chaque fichier audio en PCM brut (`s16le 44100Hz stéréo`) via un FFmpeg décodeur avec `-re` (vitesse réelle), et pousse les chunks sur le `stdin` d'un unique FFmpeg muxeur HLS. Ce dernier voit un flux PCM continu et ne produit jamais de saut de numéro de segment.

```
AudioPipe._feed()
  └── FFmpeg décodeur (-re) → PCM stdout
        └── écrit par chunks sur stdin
              └── FFmpeg HLS muxeur → seg00001.ts, seg00002.ts ...
```

### SegmentWatcher + ts_inject

Un thread dédié surveille le dossier HLS toutes les 500 ms. Dès qu'un nouveau segment `.ts` apparaît et que sa taille est stable (deux mesures identiques à 150 ms d'intervalle), il appelle le binaire externe `ts_inject` qui :

- lit le PTS réel du segment audio,
- patche la PMT (ajout du stream_type `0x15`, PID `0x0015`),
- construit un tag ID3v2.3 avec les frames `TIT2` (titre) et `TPE1` (artiste),
- réécrit le segment de façon atomique.

Les players HLS compatibles (HLS.js, Safari, VLC) affichent ainsi le titre et l'artiste en cours.

### Base de données MariaDB

Schéma à quatre tables :

```
genre       (id_genre, nom_genre)
artiste     (id_artiste, nom_artiste)
titre       (id_titre, nom_titre, chemin, id_artiste, id_genre, duree)
programmation (id_prog, date_prog, ordre, id_titre)
```

La table `programmation` associe une date à une liste ordonnée de titres (`ordre`). Un script externe (non inclus dans ce dépôt) est chargé de peupler ces tables depuis un dossier de fichiers audio.

---

## Modes de fonctionnement

### Mode LIVE (OBS/Mixxx connecté)

OBS Studio ou Mixxx diffuse un flux RTMP vers nginx-rtmp. Le superviseur reçoit le webhook `on_publish`, annule tout délai de grâce en cours, arrête le pipe fallback et lance FFmpeg en mode relai RTMP→HLS. Le SegmentWatcher est notifié du titre courant via l'API `/set_track` (à appeler manuellement ou depuis un script OBS).

```
OBS → RTMP :1935 → nginx-rtmp → [webhook] → superviseur → FFmpeg → HLS
```

### Mode FALLBACK DB (programmation du jour disponible)

Au démarrage, et à chaque rechargement (minuit ou `/reload_schedule`), le superviseur charge depuis MariaDB la liste des titres programmés pour la journée courante, triés par `ordre`. Les titres sont lus dans cet ordre. Titre et artiste proviennent directement de la base — `mutagen` n'est pas sollicité.

Lorsque la file est épuisée en cours de journée, le superviseur bascule automatiquement en mode aléatoire pour le reste de la journée.

```
MariaDB (programmation du jour)
  └── Playlist._queue (ordonnée)
        └── AudioPipe → FFmpeg HLS
```

### Mode FALLBACK aléatoire (aucune programmation)

Si la table `programmation` ne contient aucune entrée pour la date du jour, ou si PyMySQL est absent, ou si la connexion MariaDB échoue, le superviseur bascule en lecture aléatoire depuis `/opt/webradio/music/`. Les fichiers sont mélangés (Fisher-Yates) avec garantie de non-répétition immédiate du dernier morceau en tête de nouvelle liste. Les tags titre/artiste sont lus via `mutagen` si disponible, sinon le nom de fichier (sans extension) est utilisé.

```
/opt/webradio/music/*.m4a  (shuffle)
  └── Playlist._queue (aléatoire)
        └── AudioPipe → FFmpeg HLS
```

### Transitions entre modes

```
Démarrage
  └── Charge programmation DB
        ├── DB disponible et non vide → FALLBACK DB
        └── DB vide ou indisponible  → FALLBACK aléatoire

OBS connecte (on_publish)
  └── Annule délai de grâce éventuel
        └── → LIVE

OBS déconnecte (on_unpublish)
  └── Délai de grâce 8s
        ├── OBS reconnecte dans les 8s → reste LIVE
        └── Timeout → FALLBACK (DB ou aléatoire selon le jour)

Fin de programmation DB en cours de journée
  └── → FALLBACK aléatoire (reste de la journée)

Minuit
  └── Recharge programmation DB du nouveau jour
        ├── Programmation trouvée → FALLBACK DB
        └── Vide → FALLBACK aléatoire
```

---

## API interne du superviseur

Le superviseur expose un serveur HTTP sur `127.0.0.1:8089` (non exposé publiquement).

### Webhooks entrants (appelés par les scripts shell)

| Méthode | Route | Appelé par | Effet |
|---|---|---|---|
| `POST` | `/on_publish` | `on_publish.sh` | Déclenche le basculement → LIVE |
| `POST` | `/on_unpublish` | `on_unpublish.sh` | Démarre le délai de grâce → FALLBACK |

### API de contrôle et monitoring

| Méthode | Route | Description |
|---|---|---|
| `GET` | `/status` | État complet en JSON |
| `PUT` | `/set_track` | Forcer titre/artiste en mode LIVE |
| `POST` | `/reload_schedule` | Recharger la programmation DB immédiatement |
| `GET` | `/schedule` | Voir la file de lecture restante pour aujourd'hui |

#### GET /status

```json
{
  "mode": "fallback",
  "live": false,
  "listeners": 0,
  "segments": 142,
  "current_title": "Blue in Green",
  "current_artist": "Miles Davis",
  "playlist_source": "db",
  "last_publish": 1718000000.0,
  "last_unpublish": 1718003600.0,
  "ffmpeg_pid": 12345
}
```

Champs notables :

- `mode` : `"live"` | `"fallback"` | `"starting"`
- `playlist_source` : `"db"` (programmation MariaDB) | `"random"` (dossier music)
- `ffmpeg_pid` : `null` si FFmpeg n'est pas en cours d'exécution

#### PUT /set_track

Met à jour les métadonnées ID3 injectées dans les segments HLS à venir. Utile en mode LIVE pour afficher le titre diffusé par OBS ou Mixxx.

```bash
curl -X PUT http://127.0.0.1:8089/set_track \
     -H "Content-Type: application/json" \
     -d '{"title": "So What", "artist": "Miles Davis"}'
```

Réponse :
```json
{"ok": true, "title": "So What", "artist": "Miles Davis"}
```

#### POST /reload_schedule

Force le rechargement immédiat de la programmation du jour sans redémarrer le superviseur. À appeler après avoir inséré ou modifié des entrées dans la table `programmation` pour la date courante.

```bash
curl -X POST http://127.0.0.1:8089/reload_schedule
```

Réponse :
```json
{"ok": true, "date": "2025-06-01", "source": "db", "queued": 24}
```

#### GET /schedule

Retourne la file de lecture restante pour aujourd'hui sans la consommer.

```bash
curl http://127.0.0.1:8089/schedule
```

Réponse :
```json
{
  "date": "2025-06-01",
  "source": "db",
  "count": 3,
  "items": [
    {"position": 1, "title": "Autumn Leaves", "artist": "Bill Evans", "file": "autumn_leaves.m4a"},
    {"position": 2, "title": "Waltz for Debby", "artist": "Bill Evans", "file": "waltz_debby.m4a"},
    {"position": 3, "title": "Peace Piece", "artist": "Bill Evans", "file": "peace_piece.m4a"}
  ]
}
```

---

## Format des segments HLS

Les segments sont des fichiers MPEG-TS (`seg00001.ts`, `seg00002.ts`…) contenant :

- Audio AAC-LC, 192 kbps, 44100 Hz, stéréo
- Un tag ID3v2.3 injecté par `ts_inject` avec `TIT2` (titre) et `TPE1` (artiste)
- Une PMT patchée avec `stream_type=0x15` et `PID=0x0015` pour la piste de métadonnées

La playlist `.m3u8` est mise à jour en continu avec une fenêtre de 10 segments (30 secondes de buffer). Les anciens segments sont supprimés automatiquement (`delete_segments`).

---

## Arborescence des fichiers

```
/opt/webradio/
├── hls/                    # segments HLS générés par FFmpeg
│   ├── stream.m3u8         # playlist HLS courante
│   ├── seg00001.ts
│   └── ...
├── music/                  # bibliothèque audio (fichiers .m4a normalisés)
├── logs/
│   ├── supervisor.log
│   └── nginx-error.log
└── scripts/
    ├── on_publish.sh
    └── on_unpublish.sh
```
