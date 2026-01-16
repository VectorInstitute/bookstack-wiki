# Vector Bookstack Wiki

This repository contains the instructions on setting up a Bookstack Wiki. 

The Vector Institute Bookstack is hosted at [bookstack.vectorinstitute.ai](https://bookstack.vectorinstitute.ai)

For instructions on deploying the system refer to [DEPLOY.md](docs/DEPLOY.md)

## Details

This implementation use docker compose and docker containers to deploy a Bookstack instance within a google cloud compute instance.

- It is heavily based on this [tutorial video](https://www.youtube.com/watch?v=dbDzPIv8Cf8). 
- The docker container that we use is maintained by [LinuxServer.io](https://www.linuxserver.io) and is hosted in the [linuxserver/docker-bookstack](https://github.com/linuxserver/docker-bookstack) repository. 
- The aieng-auth OIDC server is used for authentication, details on that project or setting up your own OIDC server can be found in the [VectorInstitute/aieng-auth](https://github.com/VectorInstitute/aieng-auth) repository.
