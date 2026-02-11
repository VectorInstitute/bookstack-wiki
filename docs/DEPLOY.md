# Deploy Bookstack Instance with GCP Instance

## 1. Initialize gcloud:
> [!NOTE]
> The Vector Bookstack instance is currently running on a GCP compute intance in the Vector `Coderd` project

First make sure you have access to a GCP project with compute credits and install the [Google Cloud CLI](https://docs.cloud.google.com/sdk/docs/install-sdk)

Make sure to set your default compute region and zone gcp. All zones in the `northamerica-northeast2` region are located in Toronto. If you just installed `gcloud` you'll set one as part of the recommended `gcloud init` command. Otherwise change it with:

```bash
gcloud config set compute/region northamerica-northeast2
gcloud config set compute/zone northamerica-northeast2-b
```

## 2. Reserve static IP addresses:
First you must reserve a regional static IP address for the VM so that if you restart your instance it will always have the same IP address. Reserve one with:

```bash
gcloud compute addresses create {ADDRESS_NAME} \
    --region northamerica-northeast2  # Or whatever region you plan on using
```

You also need a global static IP for the HTTPS load balancer:

```bash
gcloud compute addresses create {LB_ADDRESS_NAME} --global
```

Run the following command and note both IP addresses:

```bash
gcloud compute addresses list
```
> [!WARNING]
> I have not tested most of the GCP commands in sections 3 to 5 as I used the google cloud console UI to create the instance, snapshot schedule, firewall rules, etc.

## 3.  Create GCP VM instance:
Create an GCP virtual machine (VM) instance to run the bookstack instance using the following command.


```bash
gcloud compute instances create {INSTANCE_NAME} \
    --image-family ubuntu-2404-lts-amd64 \
    --image-project ubuntu-os-cloud \
    --zone northamerica-northeast2-b \
    --address {IP_ADDRESS} \  # The IP address you reserved for the instance
    --machine-type e2-medium \
    --boot-disk-size 64GB \  # Ensure you have enough space as database is stored right on disk
```

## 4. Create a snapshot schedule:

Currently we don't do any special form of backups for the bootstack instance (although it is possible). Instead we rely on the GCP snapshot feature to just back up the entire disk on a set schedule. This is much simpler and easy to implement. First create a snapshot schedule:

```bash
gcloud compute resource-policies create snapshot-schedule {SHCEDULE_NAME} \
    --description {SCHEDULE DESCRIPTION} \
    --region northamerica-northeast2 \  # Or whatever region you are using
    --max-retention-days 14 \  # Number of days to keep the snapshot before deleting it
    --daily-schedule \  # Alternatively use the --hourly-schedule or --weekly-schedule flags
    --start-time 7:00  \  # The time in UTC to take the snapshot. 7:00 is 2:00am EST
    --on-source-disk-delete keep-auto-snapshots  # If you delete the instance, snapshots will be kept indefinitely unless deleted
    --tags http-server,https-server  # These tags apply a firewall rule to allow traffic on http/s ports
```

You now must attach the snapshot schedule to the boot disk of the VM instance you just created. First use the following command to get the name of the boot disk for the VM instance:

```bash
gcloud compute instances describe {INSTANCE_NAME} --format='get(disks[0].deviceName)'
```

Then attach the schedule to that boot disk using:

```bash
gcloud compute disks add-resource-policies {BOOT_DISK_NAME} \
    --resource-policies {SCHEDULE_NAME} \
    --zone northamerica-northeast2-b  # The zone your boot disk is in. Likely the same as compute instance
```

See [Create snapshot schedules](https://docs.cloud.google.com/compute/docs/disks/scheduled-snapshots#attach_snapshot_schedule) for further documentation.

## 5. Allow traffic from GCP load balancer and health checks:

Create a firewall rule that allows the GCP load balancer and health check probes to reach port 80 on the VM. The source ranges are Google's well-known load balancer and health check IP ranges:

```bash
gcloud compute firewall-rules create {FIREWALL_RULE_NAME} \
    --description "Allow GCP LB and health checks to reach BookStack on port 80" \
    --allow tcp:80 \
    --source-ranges 130.211.0.0/22,35.191.0.0/16 \
    --target-tags http-server \
    --direction INGRESS
```

> [!NOTE]
> This rule restricts port 80 access to only Google's internal load balancer infrastructure. The VM's port 80 is not reachable from the public internet.

See [Use VPC firewall rules](https://docs.cloud.google.com/firewall/docs/using-firewalls) and [firewall-rules create](https://docs.cloud.google.com/sdk/gcloud/reference/compute/firewall-rules/create) for further documentation.

## 6. Install Dependencies on Instance:

First ssh into the instance using:

```bash
gcloud compute ssh {INSTANCE_NAME}
```

Then install docker and give your account permission to use it:

```bash
# Install docker using convenience script
curl -fsSL https://get.docker.com -o get-docker.sh
sh get-docker.sh
# Add user to dockergroup
sudo usermod -aG docker $USER
```

## 7. Create docker network:

This project assumes that a dockernetwork named `bookstacknetwork` exists. It is useed to allow docker containers to communicate with one another easily. Create a docker network using:

```bash
# Check if bookstacknetwork exists
docker network ls
# If not create it
docker network create bookstacknetwork
```

## 8. Setup the Bookstack instance:

Clone this repository onto the instance. You may need to install git first. 

Create a `.env` file in your local `bookstack-wiki` repo that contains authentication information you'd like to use. The database credentials can be whatever you want and I think you can even change them upon restart since it is spinning up a new instance everytime. 

The important credentials are for the OIDC server that allows users to log in with their Vector account. We use the [aieng-auth](https://github.com/VectorInstitute/aieng-auth) for authentication on the Vector Bookstack. You'll need the OIDC client ID as well as the client secret/key. You can get these from Amrit or whoever is currently maintaining aieng-auth. 

An example `.env` file is included in this repo at [example.env](../example.env)

## 9. Set App URL and key:

Bookstack settings can be configured through environment variables. These are set in [bookstack.env](../bookstack.env). You will have to override both the `APP_URL` and `APP_KEY` variables.

The `APP_URL` must be a domain name that points to the global static IP reserved for the load balancer (not the VM's regional IP). If you do not have a domain, you can set this variable to the IP address itself.

The `APP_KEY` must be a unique session encryption key. Generate one with the following command:

```bash
docker pull lscr.io/linuxserver/bookstack:latest  # Pull the bookstack image
docker run -it --rm --entrypoint /bin/bash lscr.io/linuxserver/bookstack:latest appkey  # Generate app key
```

Additionally, if you are creating a new instance you will have to set `AUTH_AUTO_INITIATE` to `false`.

## 10. OPTIONAL - Configure Bookstack Settings:

Optionally, you can configure other bookstack settings through the bookstack.env environment variables. 

Refer to the [Bookstack admin documentation](https://www.bookstackapp.com/docs/admin/installation/) for information on how to configure your bookstack instance to your liking. 

The `env.example.complete` file in the Bookstack github repo ([repo](https://github.com/BookStackApp/BookStack/tree/development), [env.example.complete](https://github.com/BookStackApp/BookStack/blob/development/.env.example.complete)) contains a complete list of all the environment variables that can be used with Bookstack and their default values. Make sure to switch to release that corresponds to the Bookstack version you are using to ensure accuracy. 

This project uses the latest version by default with the latest image tag. Check the version using:

```bash
# Bookstack version is listed under Config->Labels->build_version and Config->Labels->org.opencontainers.image.version
docker image inspect lscr.io/linuxserver/bookstack:latest 
```

## 11. Start Bookstack instance:

Navigate to the repo and use docker compose to start the application.

```bash
cd bookstack-wiki
docker compose pull  # Pull container images
docker compose up -d  # -d flag just detaches you from the logs
```

## 12. Set up HTTPS with GCP Load Balancer:

SSL is handled by a GCP External Application Load Balancer with a Google-managed certificate. The load balancer terminates SSL at Google's edge and forwards plain HTTP to the VM on port 80 over Google's internal network.

### a. Create a Google-managed SSL certificate:

```bash
gcloud compute ssl-certificates create {CERT_NAME} \
    --domains={APP_URL} \
    --global
```

> [!NOTE]
> The certificate will not activate until DNS for the domain points to the load balancer's global IP address (see step d). Provisioning can take up to 24 hours after DNS is updated but is usually much faster.

### b. Create an instance group and health check:

```bash
# Create an unmanaged instance group and add the VM to it
gcloud compute instance-groups unmanaged create {IG_NAME} \
    --zone=northamerica-northeast2-b

gcloud compute instance-groups unmanaged add-instances {IG_NAME} \
    --zone=northamerica-northeast2-b \
    --instances={INSTANCE_NAME}

gcloud compute instance-groups unmanaged set-named-ports {IG_NAME} \
    --named-ports=http:80 \
    --zone=northamerica-northeast2-b

# Create a health check (use a path that returns HTTP 200)
gcloud compute health-checks create http {HEALTH_CHECK_NAME} \
    --port=80 \
    --request-path=/
```

### c. Create the backend service, URL map, proxy, and forwarding rule:

```bash
# Backend service
gcloud compute backend-services create {BACKEND_NAME} \
    --protocol=HTTP \
    --port-name=http \
    --health-checks={HEALTH_CHECK_NAME} \
    --global

gcloud compute backend-services add-backend {BACKEND_NAME} \
    --instance-group={IG_NAME} \
    --instance-group-zone=northamerica-northeast2-b \
    --global

# URL map (routes all traffic to the backend)
gcloud compute url-maps create {URL_MAP_NAME} \
    --default-service={BACKEND_NAME}

# HTTPS target proxy (ties the SSL cert to the URL map)
gcloud compute target-https-proxies create {HTTPS_PROXY_NAME} \
    --ssl-certificates={CERT_NAME} \
    --url-map={URL_MAP_NAME}

# Forwarding rule (binds the global IP to the proxy on port 443)
gcloud compute forwarding-rules create {FORWARDING_RULE_NAME} \
    --global \
    --address={LB_ADDRESS_NAME} \
    --target-https-proxy={HTTPS_PROXY_NAME} \
    --ports=443
```

### d. Update DNS:

Point `{APP_URL}` to the global load balancer IP (reserved in step 2). The Google-managed SSL certificate will automatically provision once DNS resolves correctly.

### e. Verify:

Once DNS has propagated and the certificate is active, you should be able to access your bookstack instance at `https://{APP_URL}`

## 13. Log into Bookstack:

Log in to your bookstack instance with the default admin credentials:

```
Email:      admin@admin.com
Password:   password
```

Now you will be able to modify settings. 

If you are using an OIDC, you'll want to give your account created through the OIDC server admin permissions. 

1. Log out of the admin account and log in with the OIDC server you set up. 
2.  Click "Log in with VectorInstitute" (or something along those lines) and log in with you OIDC credentials. 
    - (By default this repo has named the OIDC server "VectorInstitute" but you may have changed it.) For the aieng-auth OIDC server this is just your Vector gmail account. 
    - Logging in will link/create your account in bookstack.
3. Log out of your account and log back in with the admin account
4. Go to Settings -> Users -> {Your User Account} and assign yourself the Admin role under user roles

Now that your OIDC account is an admin. You can make this the default log in method by changing `AUTH_AUTO_INITIATE` back to `true`. For the change to take effect you'll have to restart the instance:

```bash
cd bookstack-wiki
docker compose down  # Shut down bookstack instance
docker compose up -d  # Restart instance
```

See [MANAGE.md](MANAGE.md) for more details on managing your bookstack instance.