/*
 * ts_inject.c — Injection de métadonnées ID3 dans un segment MPEG-TS HLS
 * =========================================================================
 * Usage :
 *   ts_inject --input seg.ts --title "Titre" [--artist "Artiste"] [--pmt-pid 4096]
 *
 * Ce programme :
 *   1. Lit le segment .ts en entrée
 *   2. Lit le PTS réel du premier paquet PES audio
 *   3. Patche la PMT pour y déclarer le PID de métadonnées (0x0015)
 *   4. Construit un tag ID3v2.3 (TIT2 + TPE1) encapsulé en PES
 *   5. Préfixe les paquets TS ID3 au segment
 *   6. Écrit le résultat de façon atomique (fichier tmp → rename)
 *
 * Compilation :
 *   gcc -O2 -Wall -o ts_inject ts_inject.c
 *
 * Dépendances : aucune (libc standard uniquement)
 *
 * Compatible : Linux, macOS, tout POSIX — fonctionne sur Raspberry Pi
 */

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <stdint.h>
#include <errno.h>
#include <sys/stat.h>

/* ── Constantes MPEG-TS ───────────────────────────────────────────────── */
#define TS_PACKET_SIZE   188
#define TS_SYNC_BYTE     0x47
#define ID3_PID          0x0015   /* PID metadata timed — même valeur que ffmpeg */
#define ID3_STREAM_TYPE  0x15     /* ISO 13818-1 : Metadata carried in PES packets */
#define DEFAULT_PMT_PID  0x1000   /* PMT PID par défaut généré par ffmpeg */

/* ── Tailles maximales ────────────────────────────────────────────────── */
#define MAX_TITLE_LEN    512
#define MAX_ARTIST_LEN   512
#define MAX_SEGMENT_SIZE (8 * 1024 * 1024)  /* 8 MB — largement suffisant */
#define MAX_ID3_SIZE     2048
#define MAX_PES_SIZE     (MAX_ID3_SIZE + 64)
#define MAX_TS_OUT_SIZE  (MAX_SEGMENT_SIZE + 16 * TS_PACKET_SIZE)

/* ─────────────────────────────────────────────────────────────────────── */
/* CRC32 MPEG-2 (polynôme 0x04C11DB7)                                      */
/* ─────────────────────────────────────────────────────────────────────── */
static uint32_t crc32_mpeg(const uint8_t *data, size_t len)
{
    uint32_t crc = 0xFFFFFFFF;
    for (size_t i = 0; i < len; i++) {
        crc ^= (uint32_t)data[i] << 24;
        for (int b = 0; b < 8; b++) {
            if (crc & 0x80000000)
                crc = (crc << 1) ^ 0x04C11DB7;
            else
                crc <<= 1;
        }
    }
    return crc;
}

/* ─────────────────────────────────────────────────────────────────────── */
/* Lecture du PTS du premier paquet PES audio                              */
/* ─────────────────────────────────────────────────────────────────────── */
static int64_t read_first_pts(const uint8_t *ts, size_t ts_len)
{
    for (size_t i = 0; i + TS_PACKET_SIZE <= ts_len; i += TS_PACKET_SIZE) {
        const uint8_t *pkt = ts + i;
        if (pkt[0] != TS_SYNC_BYTE) continue;

        int pusi      = (pkt[1] >> 6) & 1;
        int has_adapt = (pkt[3] >> 5) & 1;
        int has_pay   = (pkt[3] >> 4) & 1;

        if (!pusi || !has_pay) continue;

        /* Calculer l'offset du payload (sauter l'adaptation field) */
        int off = 4;
        if (has_adapt) {
            int adapt_len = pkt[4];
            off += 1 + adapt_len;
        }
        if (off + 14 > TS_PACKET_SIZE) continue;

        /* Vérifier le start code PES 0x000001 */
        if (pkt[off] != 0x00 || pkt[off+1] != 0x00 || pkt[off+2] != 0x01)
            continue;

        uint8_t stream_id     = pkt[off + 3];
        uint8_t pts_dts_flags = (pkt[off + 7] >> 6) & 0x03;

        /* stream_id audio AAC : 0xC0–0xDF */
        if (stream_id < 0xC0 || stream_id > 0xDF) continue;
        if (pts_dts_flags != 0x02 && pts_dts_flags != 0x03) continue;

        int p = off + 9;  /* début des 5 octets PTS */
        if (p + 5 > TS_PACKET_SIZE) continue;

        int64_t pts =
            ((int64_t)(pkt[p]     & 0x0E) << 29) |
            ((int64_t) pkt[p + 1]         << 22) |
            ((int64_t)(pkt[p + 2] & 0xFE) << 14) |
            ((int64_t) pkt[p + 3]         <<  7) |
            ((int64_t)(pkt[p + 4] & 0xFE) >>  1);

        return pts;
    }
    return 0;
}

/* ─────────────────────────────────────────────────────────────────────── */
/* Encodage PTS 33 bits → 5 octets format PES                              */
/* ─────────────────────────────────────────────────────────────────────── */
static void encode_pts(int64_t pts, uint8_t out[5])
{
    out[0] = 0x21 | (uint8_t)((pts >> 29) & 0x0E);
    out[1] = (uint8_t)((pts >> 22) & 0xFF);
    out[2] = 0x01 | (uint8_t)((pts >> 14) & 0xFE);
    out[3] = (uint8_t)((pts >>  7) & 0xFF);
    out[4] = 0x01 | (uint8_t)((pts <<  1) & 0xFE);
}

/* ─────────────────────────────────────────────────────────────────────── */
/* Construction du tag ID3v2.3                                             */
/* ─────────────────────────────────────────────────────────────────────── */

/* Écrit un frame ID3v2.3 texte dans `buf`, retourne la taille écrite */
static size_t write_id3_frame(uint8_t *buf, const char *key, const char *value)
{
    size_t vlen   = strlen(value);
    size_t paylen = 1 + vlen;     /* encoding byte (0x03 = UTF-8) + data */

    /* Frame header : key(4) + size(4 big-endian) + flags(2) */
    memcpy(buf, key, 4);
    buf[4] = (uint8_t)((paylen >> 24) & 0xFF);
    buf[5] = (uint8_t)((paylen >> 16) & 0xFF);
    buf[6] = (uint8_t)((paylen >>  8) & 0xFF);
    buf[7] = (uint8_t)( paylen        & 0xFF);
    buf[8] = 0x00;   /* flags high */
    buf[9] = 0x00;   /* flags low  */

    /* Payload : encoding + texte UTF-8 */
    buf[10] = 0x03;  /* UTF-8 */
    memcpy(buf + 11, value, vlen);

    return 10 + paylen;
}

/* Encode n en syncsafe 4 octets (ID3v2 tag size) */
static void syncsafe4(uint32_t n, uint8_t out[4])
{
    out[3] = n & 0x7F; n >>= 7;
    out[2] = n & 0x7F; n >>= 7;
    out[1] = n & 0x7F; n >>= 7;
    out[0] = n & 0x7F;
}

/*
 * Construit un tag ID3v2.3 complet dans `buf`.
 * Retourne la taille totale du tag.
 */
static size_t build_id3_tag(uint8_t *buf, const char *title, const char *artist)
{
    uint8_t frames[MAX_ID3_SIZE];
    size_t  flen = 0;

    flen += write_id3_frame(frames + flen, "TIT2", title);
    if (artist && artist[0])
        flen += write_id3_frame(frames + flen, "TPE1", artist);

    /* Header ID3v2 : "ID3" + version(2) + flags(1) + syncsafe size(4) */
    buf[0] = 'I'; buf[1] = 'D'; buf[2] = '3';
    buf[3] = 0x03; buf[4] = 0x00;  /* ID3v2.3 */
    buf[5] = 0x00;                  /* flags */
    syncsafe4((uint32_t)flen, buf + 6);
    memcpy(buf + 10, frames, flen);

    return 10 + flen;
}

/* ─────────────────────────────────────────────────────────────────────── */
/* Construction du PES ID3                                                 */
/* ─────────────────────────────────────────────────────────────────────── */
static size_t build_pes_id3(uint8_t *buf, const uint8_t *id3, size_t id3_len, int64_t pts)
{
    uint8_t pts_bytes[5];
    encode_pts(pts, pts_bytes);

    /* PES optional header : flags(2) + header_data_length(1) + PTS(5) */
    uint8_t pes_opt[8];
    pes_opt[0] = 0x80;   /* marker bits */
    pes_opt[1] = 0x80;   /* PTS present */
    pes_opt[2] = 0x05;   /* longueur header optionnel */
    memcpy(pes_opt + 3, pts_bytes, 5);

    size_t pes_payload_len = sizeof(pes_opt) + id3_len;
    size_t total = 0;

    /* Start code + stream_id (0xBD = private stream 1) */
    buf[total++] = 0x00;
    buf[total++] = 0x00;
    buf[total++] = 0x01;
    buf[total++] = 0xBD;

    /* PES packet length (big-endian) */
    buf[total++] = (uint8_t)((pes_payload_len >> 8) & 0xFF);
    buf[total++] = (uint8_t)( pes_payload_len       & 0xFF);

    memcpy(buf + total, pes_opt, sizeof(pes_opt));
    total += sizeof(pes_opt);
    memcpy(buf + total, id3, id3_len);
    total += id3_len;

    return total;
}

/* ─────────────────────────────────────────────────────────────────────── */
/* Encapsulation PES → paquets TS                                          */
/* ─────────────────────────────────────────────────────────────────────── */
static size_t build_ts_packets(uint8_t *out, const uint8_t *payload,
                                size_t payload_len, uint16_t pid)
{
    size_t out_len = 0;
    size_t offset  = 0;
    uint8_t cc     = 0;

    while (offset < payload_len) {
        int    pusi  = (offset == 0) ? 1 : 0;
        size_t space = TS_PACKET_SIZE - 4;
        size_t chunk = payload_len - offset;
        if (chunk > space) chunk = space;
        size_t pad = space - chunk;

        out[out_len++] = TS_SYNC_BYTE;
        out[out_len++] = (uint8_t)((pusi << 6) | ((pid >> 8) & 0x1F));
        out[out_len++] = (uint8_t)(pid & 0xFF);
        out[out_len++] = (uint8_t)(0x10 | (cc & 0x0F));

        memcpy(out + out_len, payload + offset, chunk);
        out_len += chunk;
        memset(out + out_len, 0xFF, pad);
        out_len += pad;

        offset += chunk;
        cc = (cc + 1) & 0x0F;
    }
    return out_len;
}

/* ─────────────────────────────────────────────────────────────────────── */
/* Patch PMT — ajoute l'entrée stream_type=0x15 PID=ID3_PID               */
/* ─────────────────────────────────────────────────────────────────────── */
static int patch_pmt_packet(uint8_t *pkt, uint16_t pmt_pid)
{
    if (pkt[0] != TS_SYNC_BYTE) return 0;

    uint16_t pid  = (uint16_t)(((pkt[1] & 0x1F) << 8) | pkt[2]);
    int      pusi = (pkt[1] >> 6) & 1;
    if (pid != pmt_pid || !pusi) return 0;

    int pointer = pkt[4];
    int base    = 4 + 1 + pointer;

    if (pkt[base] != 0x02) return 0;  /* table_id = PMT */

    int section_len   = ((pkt[base + 1] & 0x0F) << 8) | pkt[base + 2];
    int prog_info_len = ((pkt[base + 10] & 0x0F) << 8) | pkt[base + 11];

    /* Vérifier si ID3_PID déjà déclaré */
    int pos = base + 12 + prog_info_len;
    int end = base + 3 + section_len - 4;   /* avant CRC */
    while (pos + 5 <= end) {
        uint16_t elem_pid    = (uint16_t)(((pkt[pos + 1] & 0x1F) << 8) | pkt[pos + 2]);
        int      es_info_len = ((pkt[pos + 3] & 0x0F) << 8) | pkt[pos + 4];
        if (elem_pid == ID3_PID) return 0;   /* déjà présent */
        pos += 5 + es_info_len;
    }

    /* Vérifier que la nouvelle entrée (5 octets) tient dans le paquet */
    int new_section_len = section_len + 5;
    int pmt_end = base + 3 + section_len;
    if (pmt_end + 5 > TS_PACKET_SIZE) return 0;

    /* Insérer les 5 octets avant le CRC en décalant les octets suivants */
    int insert = end;   /* = base + 3 + section_len - 4 */
    int tail   = TS_PACKET_SIZE - (insert + 5);
    if (tail < 0) tail = 0;
    memmove(pkt + insert + 5, pkt + insert, (size_t)(TS_PACKET_SIZE - insert - 5));

    pkt[insert]     = ID3_STREAM_TYPE;
    pkt[insert + 1] = (uint8_t)(0xE0 | ((ID3_PID >> 8) & 0x1F));
    pkt[insert + 2] = (uint8_t)(ID3_PID & 0xFF);
    pkt[insert + 3] = 0xF0;
    pkt[insert + 4] = 0x00;

    /* Mettre à jour section_length */
    pkt[base + 1] = (uint8_t)((pkt[base + 1] & 0xF0) | ((new_section_len >> 8) & 0x0F));
    pkt[base + 2] = (uint8_t)(new_section_len & 0xFF);

    /* Recalculer le CRC */
    int      crc_end = base + 3 + new_section_len - 4;
    uint32_t new_crc = crc32_mpeg(pkt + base, (size_t)(crc_end - base));
    pkt[crc_end]     = (uint8_t)((new_crc >> 24) & 0xFF);
    pkt[crc_end + 1] = (uint8_t)((new_crc >> 16) & 0xFF);
    pkt[crc_end + 2] = (uint8_t)((new_crc >>  8) & 0xFF);
    pkt[crc_end + 3] = (uint8_t)( new_crc        & 0xFF);

    return 1;
}

static int patch_pmt_in_ts(uint8_t *ts, size_t ts_len, uint16_t pmt_pid)
{
    int patched = 0;
    for (size_t i = 0; i + TS_PACKET_SIZE <= ts_len; i += TS_PACKET_SIZE) {
        if (patch_pmt_packet(ts + i, pmt_pid))
            patched++;
    }
    return patched;
}

/* ─────────────────────────────────────────────────────────────────────── */
/* Lecture d'un fichier entier en mémoire                                  */
/* ─────────────────────────────────────────────────────────────────────── */
static uint8_t *read_file(const char *path, size_t *out_len)
{
    FILE *f = fopen(path, "rb");
    if (!f) { perror(path); return NULL; }

    struct stat st;
    if (fstat(fileno(f), &st) != 0) { perror("fstat"); fclose(f); return NULL; }

    size_t len = (size_t)st.st_size;
    if (len < TS_PACKET_SIZE || len > MAX_SEGMENT_SIZE) {
        fprintf(stderr, "ts_inject: taille invalide (%zu octets) : %s\n", len, path);
        fclose(f); return NULL;
    }

    uint8_t *buf = malloc(len);
    if (!buf) { fprintf(stderr, "ts_inject: malloc(%zu) échoué\n", len); fclose(f); return NULL; }

    if (fread(buf, 1, len, f) != len) {
        perror("fread"); free(buf); fclose(f); return NULL;
    }
    fclose(f);
    *out_len = len;
    return buf;
}

/* ─────────────────────────────────────────────────────────────────────── */
/* Écriture atomique (tmp → rename)                                        */
/* ─────────────────────────────────────────────────────────────────────── */
static int write_file_atomic(const char *path, const uint8_t *data, size_t len)
{
    /* Construire le chemin temporaire dans le même répertoire */
    size_t plen = strlen(path);
    char  *tmp  = malloc(plen + 5);
    if (!tmp) return -1;
    memcpy(tmp, path, plen);
    memcpy(tmp + plen, ".tmp", 5);

    FILE *f = fopen(tmp, "wb");
    if (!f) { perror(tmp); free(tmp); return -1; }

    if (fwrite(data, 1, len, f) != len) {
        perror("fwrite"); fclose(f); remove(tmp); free(tmp); return -1;
    }
    fclose(f);

    if (rename(tmp, path) != 0) {
        perror("rename"); remove(tmp); free(tmp); return -1;
    }
    free(tmp);
    return 0;
}

/* ─────────────────────────────────────────────────────────────────────── */
/* Point d'entrée                                                          */
/* ─────────────────────────────────────────────────────────────────────── */
static void usage(const char *prog)
{
    fprintf(stderr,
        "Usage: %s --input FILE.ts --title TITRE [--artist ARTISTE] [--pmt-pid PID]\n"
        "\n"
        "  --input   FILE.ts    Segment MPEG-TS à modifier (in-place)\n"
        "  --title   TITRE      Titre du morceau (UTF-8)\n"
        "  --artist  ARTISTE    Artiste (optionnel, UTF-8)\n"
        "  --pmt-pid PID        PID de la PMT en décimal (défaut: 4096 = 0x1000)\n"
        "\n"
        "Exemple:\n"
        "  %s --input /hls/seg00042.ts --title \"Bohemian Rhapsody\" --artist \"Queen\"\n",
        prog, prog);
}

int main(int argc, char *argv[])
{
    const char *input    = NULL;
    const char *title    = NULL;
    const char *artist   = "";
    uint16_t    pmt_pid  = DEFAULT_PMT_PID;

    /* ── Parsing des arguments ── */
    for (int i = 1; i < argc; i++) {
        if (strcmp(argv[i], "--input") == 0 && i + 1 < argc)
            input = argv[++i];
        else if (strcmp(argv[i], "--title") == 0 && i + 1 < argc)
            title = argv[++i];
        else if (strcmp(argv[i], "--artist") == 0 && i + 1 < argc)
            artist = argv[++i];
        else if (strcmp(argv[i], "--pmt-pid") == 0 && i + 1 < argc)
            pmt_pid = (uint16_t)atoi(argv[++i]);
        else if (strcmp(argv[i], "--help") == 0 || strcmp(argv[i], "-h") == 0) {
            usage(argv[0]); return 0;
        } else {
            fprintf(stderr, "ts_inject: argument inconnu : %s\n", argv[i]);
            usage(argv[0]); return 1;
        }
    }

    if (!input || !title) {
        fprintf(stderr, "ts_inject: --input et --title sont obligatoires\n");
        usage(argv[0]); return 1;
    }

    /* ── 1. Lire le segment ── */
    size_t   ts_len = 0;
    uint8_t *ts     = read_file(input, &ts_len);
    if (!ts) return 1;

    /* ── 2. Lire le PTS audio ── */
    int64_t pts = read_first_pts(ts, ts_len);

    /* ── 3. Patcher la PMT ── */
    int pmt_patched = patch_pmt_in_ts(ts, ts_len, pmt_pid);

    /* ── 4. Construire le tag ID3 ── */
    uint8_t id3_buf[MAX_ID3_SIZE];
    size_t  id3_len = build_id3_tag(id3_buf, title, artist);

    /* ── 5. Construire le PES ── */
    uint8_t pes_buf[MAX_PES_SIZE];
    size_t  pes_len = build_pes_id3(pes_buf, id3_buf, id3_len, pts);

    /* ── 6. Encapsuler en paquets TS ── */
    size_t  ts_pkts_size = ((pes_len + (TS_PACKET_SIZE - 5)) / (TS_PACKET_SIZE - 4)) * TS_PACKET_SIZE;
    uint8_t *ts_pkts = malloc(ts_pkts_size + TS_PACKET_SIZE);
    if (!ts_pkts) { free(ts); return 1; }
    size_t ts_pkts_len = build_ts_packets(ts_pkts, pes_buf, pes_len, ID3_PID);

    /* ── 7. Assembler : paquets ID3 + segment patché ── */
    size_t  out_len = ts_pkts_len + ts_len;
    uint8_t *out    = malloc(out_len);
    if (!out) { free(ts); free(ts_pkts); return 1; }

    memcpy(out,              ts_pkts, ts_pkts_len);
    memcpy(out + ts_pkts_len, ts,     ts_len);

    free(ts);
    free(ts_pkts);

    /* ── 8. Écriture atomique ── */
    int ret = write_file_atomic(input, out, out_len);
    free(out);

    if (ret != 0) {
        fprintf(stderr, "ts_inject: échec de l'écriture vers %s\n", input);
        return 1;
    }

    /* Log lisible par le supervisor Python */
    fprintf(stderr, "ts_inject: OK pts=%.3fs pmt_patched=%d title=\"%s\"\n",
            (double)pts / 90000.0, pmt_patched, title);

    return 0;
}
