Install dependencies

```bash
# Install docker using convenience script
curl -fsSL https://get.docker.com -o get-docker.sh
sh get-docker.sh
# Add user to dockergroup
sudo usermod -aG docker $USER
```

Create docker network if it does not already exist
```bash
# Check if bookstacknetwork exists
docker network ls
# If not create it
docker network create bookstacknetwork
```

Set up firewall rule to enable port 81
- Create Firewall Rule
- Set 81 for TCP Ports
- Set source IPv4 Ranges to `0.0.0.0/0`
- Simple approach, go to vm in gcloud, click on 3 dots and click view network details. Set target to all targets on network
- Alternate approach. Select specific target tags for targets and set a target tag such as allow-tcp-81, apply the same tag to the VM Instance.

Log in create user account.

Create proxy for domain

