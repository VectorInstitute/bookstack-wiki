## BookStack on Google Cloud with Docker + Cloud SQL  
This walkthrough uses Docker Compose, a managed Cloud SQL MySQL database, a persistent disk for uploads/backups, and automated BookStack ZIP backups synced to Cloud Storage (kept 90 days). Each command includes context so the process is beginner-friendly.

---

### 1. Prepare Google Cloud  
1. **Authenticate & enable APIs**
   ```
   gcloud init
   gcloud services enable compute.googleapis.com sqladmin.googleapis.com storage.googleapis.com
   ```
   - `gcloud init` logs you in and sets default project/region/zone (for this guide we assume `us-central1` / `us-central1-a`).
   - Enabling APIs activates Compute Engine, Cloud SQL, and Cloud Storage services.

2. **Reserve a static IP** (keeps DNS stable even if the VM is recreated)
   ```
   gcloud compute addresses create bookstack-ip --region=us-central1
   STATIC_IP=$(gcloud compute addresses describe bookstack-ip --region=us-central1 --format='value(address)')
   echo "Static IP: $STATIC_IP"
   ```

---

### 2. Managed MySQL with Cloud SQL  
BookStack relies on MySQL. Using Cloud SQL means Google handles backups, updates, and point-in-time recovery.

1. **Create MySQL instance**
   ```
   gcloud sql instances create bookstack-sql \
     --database-version=MYSQL_8_0 \
     --tier=db-f1-micro \
     --region=us-central1 \
     --storage-size=20 \
     --storage-auto-increase \
     --backup-start-time=03:00 \
     --enable-bin-log
   ```
   - `backup-start-time` sets the daily automatic backup window.
   - `enable-bin-log` enables point-in-time recovery.

2. **Create database and user**
   ```
   gcloud sql databases create bookstack --instance=bookstack-sql
   gcloud sql users create bookstack --instance=bookstack-sql --password='STRONG_DB_PASSWORD'
   CONN_NAME=$(gcloud sql instances describe bookstack-sql --format='value(connectionName)')
   echo "Cloud SQL connection name: $CONN_NAME"
   ```

Why keep Cloud SQL when BookStack’s CLI backups include the DB?  
- Cloud SQL gives automated daily backups, PITR, lifecycle management, and easier scaling.  
- The BookStack ZIP (with DB dump) is your secondary safety net and enables migrations/off-site restores.

---

### 3. Cloud Storage bucket for backup ZIPs  
```
BUCKET=bookstack-backups-$(gcloud config get-value project)
gsutil mb -l us-central1 gs://$BUCKET
```
- Creates a bucket in the same region for low-latency uploads.

Apply lifecycle to auto-delete files older than 90 days:
```
cat <<'EOF' > lifecycle.json
{
  "rule": [
    { "action": { "type": "Delete" }, "condition": { "age": 90 } }
  ]
}
EOF
gsutil lifecycle set lifecycle.json gs://$BUCKET
```

---

### 4. Create VM & persistent disk  
Persistent disk keeps uploads/backups even if the VM is rebuilt.

1. **Create disk (50 GB)**
   ```
   gcloud compute disks create bookstack-data --size=50GB --type=pd-balanced --zone=us-central1-a
   ```

2. **Create VM**
   ```
   gcloud compute instances create bookstack-vm \
     --zone=us-central1-a \
     --machine-type=e2-medium \
     --image-family=ubuntu-2404-lts \
     --image-project=ubuntu-os-cloud \
     --boot-disk-size=20GB \
     --address=$STATIC_IP \
     --tags=bookstack \
     --scopes=https://www.googleapis.com/auth/cloud-platform
   ```
   - `cloud-platform` scope lets the VM call Cloud Storage and Cloud SQL APIs without extra auth.

3. **Attach persistent disk**
   ```
   gcloud compute instances attach-disk bookstack-vm --disk=bookstack-data --zone=us-central1-a
   ```

4. **Open HTTP/HTTPS ports**
   ```
   gcloud compute firewall-rules create bookstack-http --allow=tcp:80 --target-tags=bookstack
   gcloud compute firewall-rules create bookstack-https --allow=tcp:443 --target-tags=bookstack
   ```

---

### 5. SSH into VM & install prerequisites  
```
gcloud compute ssh bookstack-vm --zone=us-central1-a
```

1. **Install Docker, compose plugin, Nginx, Certbot**
   ```
   sudo apt update
   sudo apt install -y docker.io docker-compose-plugin nginx python3-certbot-nginx
   sudo usermod -aG docker $USER
   ```
   - Log out/in (or run `newgrp docker`) so you can use Docker without `sudo`.

2. **Prepare persistent disk**
   ```
   sudo mkfs.ext4 -F /dev/sdb
   sudo mkdir -p /mnt/bookstack
   sudo mount /dev/sdb /mnt/bookstack
   UUID=$(sudo blkid -s UUID -o value /dev/sdb)
   echo "UUID=$UUID /mnt/bookstack ext4 defaults,nofail 0 2" | sudo tee -a /etc/fstab
   sudo mkdir -p /mnt/bookstack/{uploads,storage,backups}
   sudo chown -R $USER:$USER /mnt/bookstack
   ```
   - `/mnt/bookstack/uploads` & `/mnt/bookstack/storage` hold BookStack data.
   - `/mnt/bookstack/backups` stores BookStack ZIPs before they are copied to GCS.

3. **Host path for Cloud SQL sockets**
   ```
   sudo mkdir -p /var/run/cloudsql
   sudo chown $USER:$USER /var/run/cloudsql
   ```

---

### 6. Compose files & environment  
Create a working directory:
```
mkdir -p ~/bookstack && cd ~/bookstack
```

1. **Environment files**

   - Cloud SQL proxy variables:
     ````bash
     ````bash
     # filepath: ~/bookstack/cloudsql-proxy.env
     INSTANCE_CONNECTION_NAME=${CONN_NAME}
     ````
   - BookStack application settings:
     ````bash
     ````bash
     # filepath: ~/bookstack/bookstack.env
     PUID=33
     PGID=33
     TZ=UTC
     APP_URL=https://wiki.example.com
     APP_KEY=base64:GENERATE_YOUR_OWN_KEY
     DB_HOST=/cloudsql/bookstack
     DB_PORT=3306
     DB_DATABASE=bookstack
     DB_USERNAME=bookstack
     DB_PASSWORD=STRONG_DB_PASSWORD
     ````
     - Generate a unique `APP_KEY` (e.g., `openssl rand -base64 32` and prepend `base64:`).
     - `PUID=33` / `PGID=33` run the container as `www-data`.

2. **Docker Compose file**  
   This mirrors the structure of your reference file but swaps the MariaDB container for the Cloud SQL proxy.

   ````yaml
   ````yaml
   # filepath: ~/bookstack/docker-compose.yml
   version: "3.9"

   services:
     cloudsql-proxy:
       image: gcr.io/cloudsql-docker/gce-proxy:1.40.2
       container_name: cloudsql-proxy
       restart: always
       env_file:
         - ./cloudsql-proxy.env
       command:
         - /cloud_sql_proxy
         - "-dir=/cloudsql"
         - "-instances=${INSTANCE_CONNECTION_NAME}=unix:/cloudsql/bookstack"
       volumes:
         - cloudsql-sockets:/cloudsql
       network_mode: host
       user: "33:33"

     bookstack:
       image: lscr.io/linuxserver/bookstack:version-v25.02
       container_name: bookstack
       restart: unless-stopped
       depends_on:
         - cloudsql-proxy
       env_file:
         - ./bookstack.env
       environment:
         - PUID=33
         - PGID=33
         - TZ=UTC
       volumes:
         - /mnt/bookstack/uploads:/config/www/bookstack/public/uploads
         - /mnt/bookstack/storage:/config/www/bookstack/storage
         - /mnt/bookstack/backups:/config/www/bookstack/backups
         - cloudsql-sockets:/cloudsql
       ports:
         - 8080:80

   volumes:
     cloudsql-sockets:
       driver: local
       driver_opts:
         type: none
         o: bind
         device: /var/run/cloudsql
   ````
   **Explanation**
   - `cloudsql-proxy` runs Google’s proxy, exposing a Unix socket at `/cloudsql/bookstack`. `network_mode: host` keeps connection local.
   - `bookstack` uses the latest LinuxServer image (replace `version-v25.02` when upgrading). Uploads/storage/backups are rooted on the persistent disk.  
   - `cloudsql-sockets` binds the host’s `/var/run/cloudsql` directory so both containers can access the Unix socket.

3. **Start the stack**
   ```
   docker compose up -d
   ```
   - Pulls images, creates containers, starts BookStack bound to port 8080.

---

### 7. Nginx reverse proxy & HTTPS  
Forward port 80/443 to the BookStack container and secure with Let’s Encrypt.

1. **Create Nginx site**
   ````bash
   ````bash
   # filepath: /etc/nginx/sites-available/bookstack
   server {
       listen 80;
       server_name wiki.example.com;

       location / {
           proxy_pass http://127.0.0.1:8080;
           proxy_set_header Host $host;
           proxy_set_header X-Real-IP $remote_addr;
           proxy_set_header X-Forwarded-Proto $scheme;
       }
   }
   ````
2. **Enable and test**
   ```
   sudo ln -s /etc/nginx/sites-available/bookstack /etc/nginx/sites-enabled/
   sudo nginx -t
   sudo systemctl reload nginx
   ```
3. **Issue TLS certificate**
   ```
   sudo certbot --nginx -d wiki.example.com --non-interactive --agree-tos -m admin@example.com
   ```
   - Certbot updates the Nginx site to redirect HTTP→HTTPS and configures auto-renewals.

---

### 8. Automated backups (daily)  
We’ll run the BookStack CLI backup inside the container, keep seven days locally, and mirror to Cloud Storage (bucket’s lifecycle deletes anything older than 90 days).

````bash
````bash
# filepath: /etc/cron.d/bookstack-backup
30 2 * * * root docker compose -f /home/$USER/bookstack/docker-compose.yml exec bookstack php artisan bookstack:backup --destination=/config/www/bookstack/backups && \
find /mnt/bookstack/backups -type f -name "bookstack-backup-*.zip" -mtime +7 -delete && \
gsutil -m cp /mnt/bookstack/backups/bookstack-backup-*.zip gs://BOOKSTACK_BACKUP_BUCKET/
````
- Replace `BOOKSTACK_BACKUP_BUCKET` with the actual bucket name.
- Ensure the VM’s default service account has Storage write access (default scopes provide this).

**What gets backed up?**  
- BookStack CLI backup includes `.env`, `public/uploads`, `storage/uploads`, `themes`, and a database dump.  
- Cloud SQL handles its own daily backups; the ZIP gives extra redundancy and supports migrations.

---

### 9. Verify the deployment  
- Visit `https://wiki.example.com` (or `http://STATIC_IP` if DNS isn’t set yet).  
- Sign up the first admin account.  
- Upload a test file to confirm it appears under `/mnt/bookstack/uploads`.

---

### 10. Maintenance cheatsheet  

| Task | Command | Purpose |
|------|---------|---------|
| Check container status | `docker compose ps` | Ensure BookStack & proxy are running. |
| View logs | `docker compose logs -f bookstack` | Diagnose errors. |
| Upgrade BookStack | `cd ~/bookstack && docker compose pull bookstack && docker compose up -d bookstack` | Pull new image tag, restart container. |
| Upgrade OS | `sudo apt update && sudo apt upgrade -y` | Apply security patches to VM. |
| Verify backups | `ls /mnt/bookstack/backups` + `gsutil ls gs://$BUCKET` | Ensure daily ZIPs exist locally & remotely. |
| Manual SQL export (optional) | `gcloud sql export sql bookstack-sql gs://$BUCKET/sql-exports/$(date +%F).sql.gz --database=bookstack` | Extra DB backup, also governed by lifecycle rule. |

---

### 11. Restore procedures  

**Scenario: VM deleted**  
1. Recreate VM, attach `bookstack-data` disk, reinstall Docker/Nginx (sections 4–7).  
2. Reuse the same `docker-compose.yml` and environment files.  
3. Run `docker compose up -d`. Your uploads/backups are still on the disk. BookStack connects to the existing Cloud SQL instance.

**Scenario: Restore from previous backup**  
1. Ensure desired ZIP is on disk (copy from GCS if needed):
   ```
   gsutil cp gs://$BUCKET/bookstack-backup-YYYY-MM-DD.zip /mnt/bookstack/backups/
   ```
2. Run restore inside container:
   ```
   docker compose exec bookstack php artisan bookstack:restore /config/www/bookstack/backups/bookstack-backup-YYYY-MM-DD.zip
   ```
3. If database needs to revert, use Cloud SQL backup:
   ```
   gcloud sql backups list --instance=bookstack-sql
   gcloud sql backups restore BACKUP_ID --restore-instance=bookstack-sql --backup-instance=bookstack-sql
   ```

---

### 12. Why Cloud SQL + BookStack ZIPs?  
- **Cloud SQL** gives managed MySQL (automatic patching, backups, PITR).  
- **BookStack ZIPs** capture app config, uploads, themes, and a DB dump in one portable file—useful for migrations or extra assurance.  
- Both together cover infrastructure failures, user errors, and off-site disaster recovery.

---

## Summary  
- Docker Compose runs BookStack and the Cloud SQL proxy in containers with pinned versions.  
- A persistent disk keeps user uploads and backup archives safe from VM replacement.  
- Cloud SQL manages MySQL durability; BookStack CLI backups provide an easy restore path.  
- Cloud Storage stores daily ZIPs with automatic 90-day retention.  
- Nginx + Certbot exposes HTTPS with auto-renewed certificates.  
- Rebuilding or migrating is as simple as re-running the compose stack and pointing it at the same disk/Cloud SQL instance.