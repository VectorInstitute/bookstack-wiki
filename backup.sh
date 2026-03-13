#!/usr/bin/env bash
# BookStack GCS Backup Script
# Backs up: MariaDB database, uploads (images + attachments), config files.
# Runs daily at 02:00 UTC. GCS lifecycle deletes objects after 30 days.
set -euo pipefail

WIKI_DIR=/bookstack-wiki
BUCKET=gs://bookstack-backups-vectorinstitute
SA_KEY=$WIKI_DIR/bookstack-backup-sa-key.json
TIMESTAMP=$(date -u +%Y-%m-%dT%H:%M:%SZ)
DATE=$(date -u +%Y-%m-%d)
TMPDIR=$(mktemp -d /tmp/bookstack-backup-XXXXXX)
trap 'rm -rf "$TMPDIR"' EXIT

log() { echo "[$(date -u +%H:%M:%S)] $*"; }

gcloud auth activate-service-account --key-file=$SA_KEY --quiet 2>&1

set -a; source "$WIKI_DIR/.env"; set +a

log "Dumping MariaDB ($DB_DATABASE)..."
docker exec bookstack_db   mysqldump -u"$DB_USERNAME" -p"$DB_PASSWORD"   --single-transaction --quick --lock-tables=false   "$DB_DATABASE" | gzip > "$TMPDIR/bookstack_db.sql.gz"
log "  DB: $(du -sh "$TMPDIR/bookstack_db.sql.gz" | cut -f1)"

log "Archiving uploads..."
sudo tar -czf "$TMPDIR/uploads.tar.gz"   -C "$WIKI_DIR/bookstack/www"   public/uploads storage/uploads 2>/dev/null || true
log "  Uploads: $(du -sh "$TMPDIR/uploads.tar.gz" | cut -f1)"

log "Archiving config..."
sudo tar -czf "$TMPDIR/config.tar.gz"   -C "$WIKI_DIR"   bookstack.env .env custom-header.html docker-compose.yaml 2>/dev/null || true
log "  Config: $(du -sh "$TMPDIR/config.tar.gz" | cut -f1)"

log "Uploading to $BUCKET/$DATE/..."
for f in bookstack_db.sql.gz uploads.tar.gz config.tar.gz; do
  gcloud storage cp --quiet "$TMPDIR/$f" "$BUCKET/$DATE/${TIMESTAMP}__${f}"
done

log "Backup complete -> $BUCKET/$DATE/"
