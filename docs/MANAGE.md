# Managing Bookstack

The Vector Bookstack instance runs as a docker compose application. There are three containers that run each of the following:

- Bookstack
- Bookstack SQL Database
- Nginx Proxy Manager

Many Bookstack settings can be configured through the UI under the `Settings` tab as long as you have admin level permissions. Refer to the [Bookstack admin documentation](https://www.bookstackapp.com/docs/admin/installation/) for details on what all the settings do. Some notable highlights:

- **Customization:** Configure the look and feel of the UI.
  - I have added a custom CSS style header ([custom-header.html](../custom-header.html)) under the `Custom HTML Head Content` field. This mainly adds some custom github style callouts, makes the header styles look more like github flavoured markdown, and makes collapsible content blocks look nicer.
- **Maintenance:** The cleanup images button here can be used to delete old images from the database that aren't being used in any pages.
- **Users and Roles:** These are self explanatory. Manage user permissions.
- **Registration:** Here I have configured Bookstack to assign the `VectorStaff` role to anyone who logs in with a vectorinstitite.ai gmail account.

> [!NOTE]
> Email server functionality is not currently working. So no notification or confirmation emails until this is fixed.

Additionally you can configure the rest of the Bookstack settings through environment variables. The way this repository does it is by loading the environment variables in [bookstack.env](../bookstack.env) into the container on startup. Any changes to the `bookstack.env` file will require a restart of the instance in order to take effect. You can restart the instance by running the following command:

```bash
cd bookstack-wiki
docker compose down  # Stop instance
docker compose up -d  # Start instance
```

If for some reason you need to change some env var settings but don't want to stop the bookstack instance, you can instead set them by adding them to the `bookstack-wiki/bookstack/www/.env` file. The `.env` file in this directory contains al lot of default bookstack settings, any settings in this file are overwritten by environment variables that are set in the container. Hence variables set in `bookstack.env` will take precedence over variables set in `bookstack/www/.env`. 

Refer to [env.example.complete](https://github.com/BookStackApp/BookStack/blob/development/.env.example.complete) for a complete list of available env var settings. There are a lot to explore.

## Additional Notes/TODO List

- I tried to use the builtin sendmail functionality for email notications etc, however the bookstack docker container does not have the sendmail package/executable. If you do set up a mail server, make sure to check the bookstack instructions. They recommend setting up an additional bookstack worker container to handle emails asynchronosly and prevent lag/slowdown
- Figure what roles should exist and what permissions each role should have.
- Look into additional security features. Note that images are somewhat less secure in bookstack than the content itself, so if there are instances where it is very important to keep an image private/secure, you may need to look into your options
- I haven't explored webhooks at all but maybe you can think of something cool to do with them
- Someone who is more knowledgeable on GCP can probably look into improving the GCP deployment.
- Currently the aieng-auth OIDC server is set up as the only authentication method. I have a setting that makes it automatically select this method to make log in faster. You can turn this off if you want.
- Replace the OIDC client secret when you get a chance.