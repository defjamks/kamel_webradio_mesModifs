# webradio

Système de web radio automatisée basé sur nginx-rtmp, FFmpeg et Python.

Diffuse en continu un flux HLS audio avec basculement automatique entre trois modes :
- **Live** : flux RTMP entrant depuis OBS Studio ou Mixxx
- **Programmation** : liste de titres ordonnée par jour, lue depuis une base MariaDB
- **Aléatoire** : lecture en shuffle des fichiers locaux si aucune programmation n'est définie

Les segments HLS sont enrichis de métadonnées ID3 (titre, artiste) via un binaire C externe `ts_inject`, compatibles avec HLS.js, Safari et VLC.

---

## Prérequis système

| Composant | Version minimale | Notes |
|---|---|---|
| Debian / Ubuntu | 10 (Buster) / 20.04 | Testé sur ces distributions |
| Python | 3.7 | Disponible en standard sur Debian 10 |
| nginx | 1.14 | Avec le module `nginx-rtmp` |
| FFmpeg | 4.1 | Disponible via `apt` |
| MariaDB | 10.3 | MySQL 5.7+ compatible |
| ts_inject | — | Binaire C à compiler (voir ci-dessous) |

---

## Dépendances Python

```bash
pip install aiohttp PyMySQL
```

Dépendance optionnelle (lecture des tags ID3 en mode aléatoire) :

```bash
pip install mutagen
```

Si `PyMySQL` est absent, le superviseur démarre en mode aléatoire uniquement sans erreur fatale.

---

## Installation

### 1. Cloner le dépôt

```bash
git clone https://github.com/VOTRE_ORG/webrad.git /opt/webradio
cd /opt/webradio
```

### 2. Créer l'arborescence

```bash
mkdir -p /opt/webradio/{hls,music,logs,scripts}
```

### 3. Installer nginx avec le module nginx-rtmp

```bash
apt install libnginx-mod-rtmp
```

Copier et activer la configuration nginx :

```bash
# Voir nginx-rtmp doc  pour nginx-rtmp.conf /etc/nginx/sites-available/webradio
ln -s /etc/nginx/sites-available/webradio /etc/nginx/sites-enabled/webradio
nginx -t && systemctl reload nginx
```

Copier le player Web:

```bash
cp web/player/index.html /var/www/html/webradio/
```

### 4. Installer les scripts shell

```bash
cp scripts/on_publish.sh scripts/on_unpublish.sh /opt/webradio/scripts/
chmod +x /opt/webradio/scripts/*.sh
```

### 5. Compiler ts_inject

```bash
gcc -O2 -o /usr/local/bin/ts_inject src/ts_inject.c
chmod +x /usr/local/bin/ts_inject
```

### 6. Créer la base de données MariaDB

```bash
mysql -u root -p < db/01_create_database.sql
```

Créer l'utilisateur applicatif :

```sql
CREATE USER 'webradio_user'@'localhost' IDENTIFIED BY 'ChangeMe!';
GRANT SELECT, INSERT, UPDATE, DELETE ON webradio.* TO 'webradio_user'@'localhost';
FLUSH PRIVILEGES;
```

Adapter les identifiants dans `supervisor.py` (section `CFG`) :

```python
"db_host": "localhost",
"db_user": "webradio_user",
"db_pass": "ChangeMe!",
```

### 7. Installer les dépendances Python

```bash
pip install aiohttp PyMySQL mutagen
```

### 8. Normaliser les fichiers audio

Tous les fichiers doivent être en M4A (AAC, 44100 Hz, stéréo) pour éviter les problèmes de timestamps au raccord entre morceaux. Un script de normalisation est fourni :

```bash
bash scripts/normalize_music.sh /opt/webradio/music
```

Ou manuellement :

```bash
for f in /opt/webradio/music/*.{flac,mp3,ogg,wav,aac}; do
    [ -f "$f" ] || continue
    ffmpeg -i "$f" -c:a aac -b:a 256k -ar 44100 -ac 2 "${f%.*}.m4a" && rm "$f"
done
```

Cette étape sera supprimée dans une future version afin de ne pas altérer la qualité des titres. L'idée est d'ajouter un check sur le bitstream afin de vérifier la correspondance avec le volume sonore et le sample rate.

### 9. Peupler la base (optionnel — premier démarrage)

Un script séparé parcourt le dossier `music/` et insère les titres en base :

```bash
python3 scripts/import_music.py /opt/webradio/music
```

Pour créer une programmation de test pour aujourd'hui :

```sql
INSERT INTO programmation (date_prog, ordre, id_titre)
SELECT CURDATE(), (@n := @n + 1), id_titre
FROM titre, (SELECT @n := 0) init
ORDER BY RAND()
LIMIT 20;
```

Note: Pour reconnaître les titres et artistes le script utilise un pattern de séparation (avec 2 underscores '__') de ces 2 information sur le nom de fichier: 
<Artiste>__<Titre>.extension
Ceci permet de peupler la base même si les fichiers source n'ont pas de metadonnées.

Le script **ne crée pas de doublons** : un fichier déjà importé (même chemin)
est ignoré lors des passes suivantes.

### Automatisation (cron)

```cron
# Scan toutes les nuits à 03h00
0 3 * * * DB_PASS=ChangeMe! python3 /opt/webradio/db/02_import_music.py \
    --dir /var/lib/webradio/music >> /var/log/webradio-import.log 2>&1
```

---

## 4. Interface web (PHP)

### Configuration de la base

Éditez `web/config.php` ou définissez les variables d'environnement :

```bash
export DB_HOST=localhost
export DB_PORT=3306
export DB_NAME=webradio
export DB_USER=webradio_user
export DB_PASS=ChangeMe!
```

### Déploiement

```bash
# Copier les fichiers web dans votre DocumentRoot
sudo cp web/*.php /var/www/html/webradio/adm/

# Permissions
sudo chown -R www-data:www-data /var/www/html/webradio/adm
```

### Pages disponibles

| URL | Description |
|-----|-------------|
| `/webradio/adm/editor.php` | Édition des titres, artistes et genres |
| `/webradio/adm/editor.php?tab=artistes` | Accès direct à l'onglet Artistes |
| `/webradio/adm/editor.php?tab=genres` | Accès direct à l'onglet Genres |
| `/webradio/adm/programmation.php` | Programmation du jour |
| `/webradio/adm/programmation.php?date=2025-12-25` | Programmation d'un jour donné |

---

## 5. Fonctionnalités de la page Programmation

- **Navigation par date** : boutons Veille / Aujourd'hui / Lendemain + sélecteur de date
- **Calcul automatique** de l'heure de passage (à partir de 00:00)
- **Réordonnancement** ▲/▼ par bouton
- **Suppression** d'un passage (les ordres sont recalculés)
- **Copie de journée** : duplique toute la grille d'un jour vers un autre

---

## 6. Sécurité

> ⚠️ Ces pages n'incluent pas de système d'authentification.
> En production, protégez-les par :

- `.htpasswd` (Apache) ou `auth_basic` (Nginx)
- Un système de session PHP complet
- Un reverse proxy avec authentification (Nginx + OAuth2 Proxy, etc.)

### 10. Lancer le superviseur

```bash
python3 /opt/webradio/supervisor.py
```

---

## Service systemd

Pour lancer le superviseur automatiquement au démarrage :

```ini
# /etc/systemd/system/webradio.service
[Unit]
Description=Web Radio Supervisor
After=network.target nginx.service mariadb.service
Wants=mariadb.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/opt/webradio
ExecStart=/usr/bin/python3 /opt/webradio/supervisor.py
Restart=always
RestartSec=5
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
```

```bash
systemctl daemon-reload
systemctl enable --now webradio
```

---

## Configuration OBS Studio

Dans **Paramètres → Diffusion** :

| Champ | Valeur |
|---|---|
| Service | Custom |
| URL | `rtmp://VOTRE_IP/live` |
| Clé de flux | `stream` |

Pour Mixxx, configurer le broadcast vers la même URL RTMP.

---

## URLs de sortie

| Usage | URL |
|---|---|
| Playlist HLS (CDN pull) | `http://VOTRE_IP:8080/live/stream.m3u8` |
| Monitoring superviseur | `http://127.0.0.1:8089/status` |
| Programmation du jour | `http://127.0.0.1:8089/schedule` |
| Stat nginx-rtmp (XML) | `http://127.0.0.1:8080/stat` |

---

## Vérifications post-démarrage

```bash
# État du superviseur
curl http://127.0.0.1:8089/status

# Programmation chargée pour aujourd'hui
curl http://127.0.0.1:8089/schedule

# Segments HLS générés
ls -lh /opt/webradio/hls/

# Vérifier un segment
ffprobe -v error -show_streams -select_streams a /opt/webradio/hls/seg00005.ts

# Logs en direct
tail -f /opt/webradio/logs/supervisor.log
```

---

## Mise à jour de la programmation à chaud

Après insertion de nouveaux titres dans MariaDB, recharger sans redémarrer :

```bash
curl -X POST http://127.0.0.1:8089/reload_schedule
```

---

## Mise à jour du titre en mode LIVE

Quand OBS est actif, envoyer le titre en cours via l'API :

```bash
curl -X PUT http://127.0.0.1:8089/set_track \
     -H "Content-Type: application/json" \
     -d '{"title": "Bohemian Rhapsody", "artist": "Queen"}'
```

---

## Documentation

- [Architecture et API détaillées](docs/ARCHITECTURE.fr.md)

---

## Licence

MIT
