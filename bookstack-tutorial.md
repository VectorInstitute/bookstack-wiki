
## Overview  
Use a managed Cloud SQL instance for the database, attach a persistent disk for BookStack uploads/backups, and mirror backups to Cloud Storage. This gives managed DB durability plus easy file snapshots.

---

### 1. Reserve external IP  
````bash
gcloud compute addresses create bookstack-ip --region=us-central1
gcloud compute addresses describe bookstack-ip --region=us-central1 --format='value(address)'
````
Reserves a static IPv4 address for DNS and prevents IP changes.

### 2. Create Cloud SQL (MySQL)  
````bash
gcloud sql instances create bookstack-sql \
  --database-version=MYSQL_8_0 \
  --tier=db-f1-micro \
  --region=us-central1 \
  --availability-type=zonal \
  --storage-size=20 \
  --storage-auto-increase
gcloud sql users set-password root --instance=bookstack-sql --password='StrongRootPassword'
gcloud sql databases create bookstack --instance=bookstack-sql
````
Sets up a managed MySQL instance with auto-growing storage.

### 3. Create VM + disk  
````bash
gcloud compute disks create bookstack-data --size=50GB --type=pd-balanced --zone=us-central1-a
gcloud compute instances create bookstack-vm \
  --zone=us-central1-a \
  --machine-type=e2-medium \
  --image-family=ubuntu-2404-lts \
  --image-project=ubuntu-os-cloud \
  --boot-disk-size=20GB \
  --network-tier=PREMIUM \
  --address=$(gcloud compute addresses describe bookstack-ip --region=us-central1 --format='value(address)') \
  --scopes=https://www.googleapis.com/auth/cloud-platform
gcloud compute instances attach-disk bookstack-vm --disk=bookstack-data --zone=us-central1-a
````
Creates runtime VM plus 50 GB persistent disk for BookStack data.

### 4. Prepare VM, mount disk  
````bash
gcloud compute ssh bookstack-vm --zone=us-central1-a
sudo apt update && sudo apt install -y nginx php-fpm php-mysql php-gd php-mbstring php-xml php-zip php-curl unzip git
lsblk
sudo mkfs.ext4 -F /dev/sdb
sudo mkdir -p /mnt/bookstack
sudo mount /dev/sdb /mnt/bookstack
sudo chown www-data:www-data /mnt/bookstack
sudo bash -c "printf 'UUID=%s /mnt/bookstack ext4 defaults,nofail 0 2\n' $(blkid -s UUID -o value /dev/sdb)" | sudo tee -a /etc/fstab
````
Installs runtime dependencies, formats the attached disk, mounts at `/mnt/bookstack`, persists mount.

### 5. Install BookStack  
````bash
cd /var/www
sudo git clone https://github.com/BookStackApp/BookStack.git
cd BookStack
sudo git checkout release
sudo cp .env.example .env
sudo chown -R www-data:www-data /var/www/BookStack
composer install --no-dev
php artisan key:generate
````
Installs BookStack release branch and dependencies.

### 6. Point uploads/storage to persistent disk  
````bash
sudo mkdir -p /mnt/bookstack/uploads /mnt/bookstack/storage /mnt/bookstack/backups
sudo rsync -a storage/ /mnt/bookstack/storage/
sudo rsync -a public/uploads/ /mnt/bookstack/uploads/
sudo mv storage storage.bak
sudo mv public/uploads uploads.bak
sudo ln -s /mnt/bookstack/storage storage
sudo ln -s /mnt/bookstack/uploads public/uploads
sudo chown -R www-data:www-data /mnt/bookstack
````
Moves mutable data to persistent disk while keeping symlinks in app tree.

### 7. Configure Nginx + PHP-FPM  
````bash
sudo tee /etc/nginx/sites-available/bookstack <<'EOF'
server {
    listen 80;
    server_name _;
    root /var/www/BookStack/public;

    index index.php index.html;
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
    location ~* \.(?:css|js|jpg|jpeg|gif|png|svg|ico|woff|woff2)$ {
        try_files $uri $uri/ @rewrite;
    }
    location @rewrite {
        rewrite ^/(.*)$ /index.php?/$1 last;
    }
}
EOF
sudo ln -s /etc/nginx/sites-available/bookstack /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl restart nginx
sudo systemctl restart php8.3-fpm
````
Sets up a minimal Nginx vhost for BookStack.

### 8. Connect to Cloud SQL  
````bash
gcloud auth application-default login   # once per VM if needed
gcloud sql instances describe bookstack-sql --format='value(connectionName)'
sudo tee /var/www/BookStack/.env <<'EOF'
// filepath: /var/www/BookStack/.env
// ...existing code...
APP_URL=http://YOUR_RESERVED_IP
DB_CONNECTION=mysql
DB_HOST=/cloudsql/YOUR_CONNECTION_NAME
DB_DATABASE=bookstack
DB_USERNAME=root
DB_PASSWORD=StrongRootPassword
CACHE_DRIVER=file
SESSION_DRIVER=file
SESSION_LIFETIME=120
QUEUE_CONNECTION=sync
APP_KEY=base64:GENERATED_KEY
EOF
```
Configure `.env` to use the Cloud SQL Unix socket. Replace placeholders with actual values.

Install Cloud SQL Proxy (auth proxy) to expose the socket:
````bash
curl -o cloud-sql-proxy https://dl.google.com/cloudsql/cloud-sql-proxy.linux.amd64
chmod +x cloud-sql-proxy
sudo mv cloud-sql-proxy /usr/local/bin/
sudo tee /etc/systemd/system/cloud-sql-proxy.service <<EOF
[Unit]
Description=Cloud SQL Proxy
After=network.target

[Service]
ExecStart=/usr/local/bin/cloud-sql-proxy --unix-socket-path=/cloudsql --port 0 YOUR_CONNECTION_NAME
Restart=always
User=www-data

[Install]
WantedBy=multi-user.target
EOF
sudo mkdir /cloudsql
sudo chown www-data:www-data /cloudsql
sudo systemctl enable --now cloud-sql-proxy
````
Ensures the PHP app reaches the managed MySQL via Unix socket.

Run migrations and seed:
````bash
cd /var/www/BookStack
php artisan migrate --force
php artisan bookstack:regenerate-permissions
````

### 9. Configure BookStack file permissions  
````bash
sudo chown -R www-data:www-data /var/www/BookStack
````

### 10. Set up HTTPS  
Use Cloud Armor + HTTPS load balancing or install certbot on the VM. Example with certbot:
````bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d wiki.example.com --non-interactive --agree-tos -m admin@example.com
````

### 11. Backup strategy  
- **Database**: Cloud SQL automated backups + point-in-time restore. Also schedule on-demand backups if needed:
  ```
  gcloud sql backups create --instance=bookstack-sql
  ```
- **Files**: Use BookStack CLI to produce zip backup onto persistent disk, then sync to Cloud Storage.

````bash
sudo -u www-data php artisan bookstack:backup --destination=/mnt/bookstack/backups
gsutil mb -l us-central1 gs://bookstack-backups-$(gcloud config get-value project)
gsutil cp /mnt/bookstack/backups/*.zip gs://bookstack-backups-$(gcloud config get-value project)/
````

Automate with cron:

````bash
sudo tee /etc/cron.d/bookstack-backup <<'EOF'
0 3 * * * www-data php /var/www/BookStack/artisan bookstack:backup --destination=/mnt/bookstack/backups && gsutil cp /mnt/bookstack/backups/*.zip gs://bookstack-backups-YOUR_BUCKET/
EOF
````

### 12. Restore summary  
- Detach reattached disk or mount new disk with backups.
- Restore BookStack zip via CLI (`php artisan bookstack:restore backup.zip`).
- Restore DB from Cloud SQL backup (`gcloud sql backups restore`).

### 13. Firewall rules  
````bash
gcloud compute firewall-rules create allow-http \
  --allow=tcp:80 \
  --target-tags=bookstack \
  --description="Allow HTTP traffic"
gcloud compute firewall-rules create allow-https \
  --allow=tcp:443 \
  --target-tags=bookstack \
  --description="Allow HTTPS traffic"
gcloud compute instances add-tags bookstack-vm --tags=bookstack --zone=us-central1-a
````
Grants HTTP/S access. Restrict SSH to trusted IPs as needed.

### Why this approach  
- Managed Cloud SQL handles replication, backups, upgrades.
- Persistent disk keeps uploads/backups safe; can snapshot or detach to new VM.
- BookStack CLI backups go to disk and GCS for off-site durability.
- Reserved IP simplifies DNS; TLS protects traffic.

Keep system patched (`sudo apt upgrade -y`), monitor disk usage, and test restores regularly.Grants HTTP/S access. Restrict SSH to trusted IPs as needed.

### Why this approach  
- Managed Cloud SQL handles replication, backups, upgrades.
- Persistent disk keeps uploads/backups safe; can snapshot or detach to new VM.
- BookStack CLI backups go to disk and GCS for off-site durability.
- Reserved IP simplifies DNS; TLS protects traffic.

Keep system patched (`sudo apt upgrade -y`), monitor disk usage, and test restores regularly.

scarere: Make these instructions clearer, more like a tutorial for someone who has never used gcloud, mysql, ngnix etc. For example i am confused about backups, the sql database is backed up by gcloud, but there is also a backup.zip which i thought included the database? Backups should ideally be automated. I should be able to configure backups to occur at a specific interval (eg. once per day), and automatically delete old backups after a specific time period (eg. 3 months). Please redo these instructions from start to finish, making them a clear tutorial with simple explanations for someone new to these tools. Also include instructions on whatever maintenance or important commands should be known once everything is set up. I might be making changes to bookstack such as adding themes, etc.

GitHub Copilot: ## Goal
Deploy BookStack on Google Cloud with managed database, persistent storage, HTTPS, and automated backups that expire after 90 days.

---

## 1. Prerequisites
1. Install the Google Cloud CLI on your computer and run:
   ```
   gcloud init
   ```
   This logs in, sets the active project, and default region/zone (e.g., `us-central1`, `us-central1-a`).

2. Enable these services:
   ```
   gcloud services enable compute.googleapis.com sqladmin.googleapis.com storage.googleapis.com
   ```

---

## 2. Reserve a Static IP
```
gcloud compute addresses create bookstack-ip --region=us-central1
gcloud compute addresses describe bookstack-ip --region=us-central1 --format='value(address)'
```
The second command prints the IP address you’ll map in DNS and set as `APP_URL`.

---

## 3. Create Cloud SQL (Managed MySQL)
```
gcloud sql instances create bookstack-sql \
  --database-version=MYSQL_8_0 \
  --tier=db-f1-micro \
  --region=us-central1 \
  --availability-type=zonal \
  --storage-size=20 \
  --storage-auto-increase \
  --backup-start-time=03:00 \
  --enable-bin-log
gcloud sql databases create bookstack --instance=bookstack-sql
gcloud sql users set-password bookstack --instance=bookstack-sql --password='STRONG_PASSWORD'
```
- Managed MySQL means Google handles patching, replication, and automatic daily backups (kept 7 days by default; point-in-time recovery is available because binary logging is enabled).

---

## 4. Create the Compute Engine VM and Data Disk
```
gcloud compute disks create bookstack-data --size=50GB --type=pd-balanced --zone=us-central1-a
gcloud compute instances create bookstack-vm \
  --zone=us-central1-a \
  --machine-type=e2-medium \
  --image-family=ubuntu-2404-lts \
  --image-project=ubuntu-os-cloud \
  --boot-disk-size=20GB \
  --network-tier=PREMIUM \
  --address=$(gcloud compute addresses describe bookstack-ip --region=us-central1 --format='value(address)') \
  --scopes=https://www.googleapis.com/auth/cloud-platform
gcloud compute instances attach-disk bookstack-vm --disk=bookstack-data --zone=us-central1-a
```
- `bookstack-data` (50 GB) stores uploads and backups. It can be detached, resized, or snapshot independently of the VM.

---

## 5. Open Firewall Ports (HTTP/HTTPS)
```
gcloud compute firewall-rules create bookstack-allow-http \
  --allow=tcp:80 \
  --target-tags=bookstack \
  --description="Allow HTTP for BookStack"
gcloud compute firewall-rules create bookstack-allow-https \
  --allow=tcp:443 \
  --target-tags=bookstack \
  --description="Allow HTTPS for BookStack"
gcloud compute instances add-tags bookstack-vm --zone=us-central1-a --tags=bookstack
```

---

## 6. Connect to the VM
```
gcloud compute ssh bookstack-vm --zone=us-central1-a
```

---

## 7. Format and Mount the Data Disk
```
lsblk                                   # identify /dev/sdb
sudo mkfs.ext4 -F /dev/sdb
sudo mkdir -p /mnt/bookstack
sudo mount /dev/sdb /mnt/bookstack
sudo chown www-data:www-data /mnt/bookstack
```
Persist the mount across reboots (copy the UUID from `blkid`):
```
sudo blkid /dev/sdb
sudo tee -a /etc/fstab <<EOF
UUID=PASTE_UUID_HERE /mnt/bookstack ext4 defaults,nofail 0 2
EOF
```
Test the mount:
```
sudo umount /mnt/bookstack
sudo mount -a
```

---

## 8. Install Base Packages
```
sudo apt update
sudo apt upgrade -y
sudo apt install -y nginx php-fpm php-mysql php-gd php-mbstring php-xml php-zip php-curl unzip git composer
```

---

## 9. Install BookStack Application
```
cd /var/www
sudo git clone https://github.com/BookStackApp/BookStack.git
cd BookStack
sudo git checkout release
sudo cp .env.example .env
sudo chown -R www-data:www-data /var/www/BookStack
sudo -u www-data composer install --no-dev --no-interaction
sudo -u www-data php artisan key:generate
```
- The `release` branch tracks stable releases.

---

## 10. Move Uploads/Storage to the Persistent Disk
```
sudo mkdir -p /mnt/bookstack/storage /mnt/bookstack/uploads /mnt/bookstack/backups
sudo rsync -a storage/ /mnt/bookstack/storage/
sudo rsync -a public/uploads/ /mnt/bookstack/uploads/
sudo mv storage storage.bak
sudo mv public/uploads uploads.bak
sudo ln -s /mnt/bookstack/storage storage
sudo ln -s /mnt/bookstack/uploads public/uploads
sudo chown -R www-data:www-data /mnt/bookstack
```
The symlinked directories now point to the persistent disk.

---

## 11. Install and Run the Cloud SQL Auth Proxy
```
curl -o cloud-sql-proxy https://dl.google.com/cloudsql/cloud-sql-proxy.linux.amd64
chmod +x cloud-sql-proxy
sudo mv cloud-sql-proxy /usr/local/bin/
gcloud sql instances describe bookstack-sql --format='value(connectionName)'
```
Create a systemd service (replace `YOUR_CONNECTION_NAME`):
````bash
sudo tee /etc/systemd/system/cloud-sql-proxy.service <<'EOF'
[Unit]
Description=Cloud SQL Auth Proxy
After=network.target

[Service]
ExecStart=/usr/local/bin/cloud-sql-proxy --unix-socket-path=/cloudsql --port 0 YOUR_CONNECTION_NAME
Restart=always
User=www-data

[Install]
WantedBy=multi-user.target
EOF
sudo mkdir /cloudsql
sudo chown www-data:www-data /cloudsql
sudo systemctl enable --now cloud-sql-proxy
sudo systemctl status cloud-sql-proxy
````

---

## 12. Configure BookStack `.env`
Replace placeholders with real values (e.g., `http://YOUR_STATIC_IP`, SQL password, generated `APP_KEY`):
````bash
sudo tee /var/www/BookStack/.env <<'EOF'
// filepath: /var/www/BookStack/.env
APP_URL=http://YOUR_STATIC_IP_OR_DOMAIN
APP_KEY=base64:PASTE_FROM_KEY_GENERATE
APP_DEBUG=false

DB_CONNECTION=mysql
DB_HOST=/cloudsql/YOUR_CONNECTION_NAME
DB_DATABASE=bookstack
DB_USERNAME=bookstack
DB_PASSWORD=STRONG_PASSWORD

CACHE_DRIVER=file
SESSION_DRIVER=file
SESSION_LIFETIME=120
QUEUE_CONNECTION=sync
EOF
sudo chown www-data:www-data /var/www/BookStack/.env
````

---

## 13. Run Database Migrations
```
cd /var/www/BookStack
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan bookstack:regenerate-permissions
```

---

## 14. Configure Nginx
````bash
sudo tee /etc/nginx/sites-available/bookstack <<'EOF'
server {
    listen 80;
    server_name _;

    root /var/www/BookStack/public;
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location ~* \.(css|js|jpg|jpeg|gif|png|svg|ico|woff|woff2)$ {
        try_files $uri $uri/ @rewrite;
    }

    location @rewrite {
        rewrite ^/(.*)$ /index.php?/$1 last;
    }
}
EOF
sudo ln -s /etc/nginx/sites-available/bookstack /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl restart nginx php8.3-fpm
````

---

## 15. (Optional) Enable HTTPS with Let’s Encrypt
```
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d wiki.example.com --non-interactive --agree-tos -m admin@example.com
```
- Certbot edits the Nginx config and sets up auto-renew. Ensure DNS points your domain to the static IP first.

---

## 16. Verify the Site
Browse to `http://STATIC_IP` or `https://DOMAIN`. Create the first admin user via the signup form.

---

## 17. Automated Backups

### 17.1 Cloud SQL Automatic Backups
- The instance already performs daily automatic backups.
- Confirm schedule:
  ```
  gcloud sql instances describe bookstack-sql --format='value(settings.backupConfiguration)'
  ```
- Optional: enable 14-day retention via binary logs by taking point-in-time restores. For longer retention, export periodically to Cloud Storage (next step).

### 17.2 BookStack CLI Backup + GCS Retention
1. Create a Cloud Storage bucket (globally unique name; replace placeholders):
   ```
   BUCKET=bookstack-backups-$(gcloud config get-value project)
   gsutil mb -l us-central1 gs://$BUCKET
   ```

2. Apply a lifecycle rule to delete objects older than 90 days:
   ```
   cat <<'EOF' > lifecycle.json
   {
     "rule": [
       {
         "action": { "type": "Delete" },
         "condition": { "age": 90 }
       }
     ]
   }
   EOF
   gsutil lifecycle set lifecycle.json gs://$BUCKET
   ```

3. Create a nightly cron job that:
   - Runs BookStack’s backup command (creates ZIP containing `.env`, uploads, storage, themes, and a database dump).
   - Copies that ZIP to Cloud Storage.
   - Cleans up local copies older than 7 days.

````bash
sudo tee /etc/cron.d/bookstack-backup <<'EOF'
# Run every day at 02:30
30 2 * * * www-data php /var/www/BookStack/artisan bookstack:backup --destination=/mnt/bookstack/backups && \
find /mnt/bookstack/backups -type f -name "bookstack-backup*.zip" -mtime +7 -delete && \
gsutil -m cp /mnt/bookstack/backups/bookstack-backup-*.zip gs://BOOKSTACK_BACKUP_BUCKET/
EOF
````

- Replace `BOOKSTACK_BACKUP_BUCKET` with the actual bucket name.
- Ensure `gsutil` authentication works on the VM (`gcloud auth login` or metadata service). Because the VM was created with `--scopes cloud-platform`, service account credentials already exist.

4. (Optional) Verify the cron job manually:
```
sudo -u www-data php /var/www/BookStack/artisan bookstack:backup --destination=/mnt/bookstack/backups
gsutil ls gs://$BUCKET
```

### 17.3 Optional: Export Cloud SQL to GCS
For periodic full exports (e.g., weekly) with 90-day retention:
```
gcloud sql export sql bookstack-sql gs://$BUCKET/sql-exports/$(date +%F)-bookstack.sql.gz --database=bookstack
```
Add another cron entry or Cloud Scheduler job as needed. GCS lifecycle will delete exports older than 90 days automatically.

---

## 18. Restoring

### 18.1 Restore Files + DB via BookStack CLI ZIP
1. Upload the desired ZIP to `/mnt/bookstack/backups`.
2. On the VM:
   ```
   sudo systemctl stop nginx php8.3-fpm
   sudo -u www-data php /var/www/BookStack/artisan bookstack:restore /mnt/bookstack/backups/bookstack-backup-YYYY-MM-DD.zip
   sudo systemctl start nginx php8.3-fpm
   ```
3. If the ZIP restoration includes database data, it runs `mysql` internally through the Cloud SQL Proxy.

### 18.2 Restore Cloud SQL from Automated Backup
```
gcloud sql backups list --instance=bookstack-sql
gcloud sql backups restore BACKUP_ID --restore-instance=bookstack-sql --backup-instance=bookstack-sql
```
- Restores the managed DB to the chosen point in time.

---

## 19. Ongoing Maintenance

| Task | Command | Notes |
|------|---------|-------|
| Update OS packages | `sudo apt update && sudo apt upgrade -y` | Run monthly; reboot if kernel updates. |
| Update BookStack | `cd /var/www/BookStack && sudo -u www-data git pull && sudo -u www-data composer install --no-dev --no-interaction && sudo -u www-data php artisan migrate --force` | Review release notes first; rerun `php artisan bookstack:regenerate-permissions`. |
| Check services | `systemctl status nginx php8.3-fpm cloud-sql-proxy` | All should show `active (running)`. |
| Resize data disk | `gcloud compute disks resize bookstack-data --size=100GB --zone=us-central1-a` then `sudo resize2fs /dev/sdb` on VM. |
| Snapshot data disk | `gcloud compute disks snapshot bookstack-data --zone=us-central1-a --snapshot-names=bookstack-data-$(date +%F)` | Optional for additional recovery points. |
| Review backups | `gsutil ls gs://$BUCKET` | Confirm daily ZIPs exist; test restore quarterly. |
| Manage themes/customizations | Store custom themes under `/mnt/bookstack/themes`. Symlink as needed. Include them in version control or separate backups. |

---

## 20. Summary
- VM hosts Nginx + PHP + BookStack code (Ubuntu 24.04).
- Persistent disk holds uploads, storage, themes, backup ZIPs.
- Cloud SQL provides managed MySQL with automatic backups.
- BookStack CLI backup runs nightly, uploads ZIPs to Cloud Storage, lifecycle prunes after 90 days.
- HTTPS handled via Certbot (or Cloud Load Balancing if preferred).
- Maintenance involves keeping packages updated, monitoring systemd services, validating backups, and managing disk space.

This setup balances ease of management (managed database, automated backups) and disaster recovery (GCS retention + persistent disk).
