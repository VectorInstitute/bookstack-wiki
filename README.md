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