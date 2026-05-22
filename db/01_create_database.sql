-- ============================================================
--  WebRadio - Script de création de la base de données MariaDB
-- ============================================================

CREATE DATABASE IF NOT EXISTS webradio
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE webradio;

-- ============================================================
--  TABLE : genre
-- ============================================================
CREATE TABLE IF NOT EXISTS genre (
    id_genre    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    nom_genre   VARCHAR(100)    NOT NULL,
    PRIMARY KEY (id_genre),
    UNIQUE KEY uq_nom_genre (nom_genre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  TABLE : artiste
-- ============================================================
CREATE TABLE IF NOT EXISTS artiste (
    id_artiste  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    nom_artiste VARCHAR(255)    NOT NULL,
    PRIMARY KEY (id_artiste),
    UNIQUE KEY uq_nom_artiste (nom_artiste)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  TABLE : titre
-- ============================================================
CREATE TABLE IF NOT EXISTS titre (
    id_titre    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    nom_titre   VARCHAR(255)    NOT NULL,
    chemin      VARCHAR(512)    NOT NULL,
    id_artiste  INT UNSIGNED    NOT NULL,
    id_genre    INT UNSIGNED    NULL DEFAULT NULL,
    duree       INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'Durée en secondes',
    PRIMARY KEY (id_titre),
    UNIQUE KEY uq_chemin (chemin),
    KEY fk_titre_artiste (id_artiste),
    KEY fk_titre_genre   (id_genre),
    CONSTRAINT fk_titre_artiste FOREIGN KEY (id_artiste)
        REFERENCES artiste (id_artiste) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_titre_genre   FOREIGN KEY (id_genre)
        REFERENCES genre   (id_genre)   ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  TABLE : programmation
-- ============================================================
CREATE TABLE IF NOT EXISTS programmation (
    id_prog     INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    date_prog   DATE            NOT NULL,
    ordre       SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Ordre de passage dans la journée',
    id_titre    INT UNSIGNED    NOT NULL,
    PRIMARY KEY (id_prog),
    UNIQUE KEY uq_date_ordre (date_prog, ordre),
    KEY fk_prog_titre (id_titre),
    CONSTRAINT fk_prog_titre FOREIGN KEY (id_titre)
        REFERENCES titre (id_titre) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  Utilisateur applicatif (à adapter selon votre environnement)
-- ============================================================
-- CREATE USER IF NOT EXISTS 'webradio_user'@'localhost' IDENTIFIED BY 'ChangeMe!';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON webradio.* TO 'webradio_user'@'localhost';
-- FLUSH PRIVILEGES;

SELECT 'Base de données webradio créée avec succès.' AS statut;
