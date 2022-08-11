# Magento Docker Setup
Pull the Bitnami docker-compose file, and run it using docker-compose:
```
curl -sSL https://raw.githubusercontent.com/bitnami/containers/main/bitnami/magento/docker-compose.yml > docker-compose.yml

docker-compose up -d
```
Navigate to http://localhost/admin to check if it works. Default login credentials are:
```
Username: user
Password: bitnami1
```

# Development inside the Container

## Github setup

Once the container is up and running, get the magento container name by running:
```
docker ps --format "table {{.Names}}"
```

* There should be at least 3 containers, one for magento, one for elasticsearch, and one for mariadb.

Log into the container by running the following, replacing `<magento_container_name>` with the name found through the command above:
```
docker exec -it <magento_container_name> /bin/bash
```

Once inside the container, install git and github CLI: 
```
curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg | dd of=/usr/share/keyrings/githubcli-archive-keyring.gpg
chmod go+r /usr/share/keyrings/githubcli-archive-keyring.gpg
echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main" | tee /etc/apt/sources.list.d/github-cli.list > /dev/null
apt update
apt install gh
apt install git
```

After these have installed, authenticate github by running: 
``` 
gh auth login 
```
* Note: You can authenticate through the web browser by opening the [github device login](https://github.com/login/device) page and entering the code provided by `gh auth login`.

---
## Using VSCode 
Install the [Remote-Containers](https://marketplace.visualstudio.com/items?itemName=ms-vscode-remote.remote-containers) extension for vscode.

From the extension toolbar on the left side bar, click on `Remote Explorer` and find your magento container. You can find the name of the magento container in the output of the following:
```
docker ps --format "table {{.Names}}"
```
Right click the container and select `Attach in New Window`. This should open a new VSCode window (on the Get Started page), remoted in to your container.

In the new window, open the file explorer from the left side bar. Click on `Open Folder` and navigate to the `/bitnami/magento` directory.


# Extensions setup
## SearchSpring_Feed Extension
To setup the SearchSpring_Feed extension, create the necessary directory structure:
```
cd /bitnami/magento
mkdir -p app/code/SearchSpring/Feed
```

Clone the SearchSpring_Feed repository into the directory that was just created:
```
git clone https://github.com/searchspring/magento2-searchspring-feed.git app/code/SearchSpring/Feed
```
* From here, either install other extensions or proceed to [Extension Installation](#extension-installation) 

---
## Extension Installation
Login as the web server user. 
```
su daemon -s /bin/bash
```

To setup the extensions in magento, first enable any installed extensions by running `php bin/magento module:enable <module_name>`, replacing `<module_name>` with the extension to be installed. For example:
```
php bin/magento module:enable SearchSpring_Feed;
```

Then complete the setup by running the following:
```
php bin/magento setup:upgrade;
php bin/magento setup:di:compile;
php bin/magento setup:static-content:deploy -f;
php bin/magento cache:flush;
```

At this point, the extensions should be running. You can verify this from inside the docker container by running: `php bin/magento module:status <module_name>`, replacing `<module_name>` with the installed extension. For example:
```
php bin/magento module:status SearchSpring_Feed
```

Confirm that magento is up and running by navigating to http://localhost/admin. 

* Note: If you are presented with an error or a blank screen, rerunning the setup commands again should clear things up. Not gonna say "rerun until it works," but maybe try that a few times. 

# Troubleshooting

* Restarting the docker container is a relatively quick way to ensure your changes are live. This can be done through Docker Desktop or through VSCode. To restart through VSCode, navigate to `Remote Explorer` on the left side bar of the magento workspace, right click on the active magento container in `Dev Containers` and select `Stop Container`. This should open a series of three prompts: first stop the container, second reload the window, third start the container.

* It may be necessary to rebuild the extensions by running the following from the `/bitnami/magento` directory as the web server user:
```
su daemon -s /bin/bash

php bin/magento setup:upgrade;
php bin/magento setup:di:compile;
php bin/magento setup:static-content:deploy -f;
php bin/magento cache:flush;
```

* You can enable printing of the error call stack on an erroring page by running the following from the `/bitnami/magento` directory:
```
bin/magento deploy:mode:set developer
```
* For generating customer data, run the `customer_generator.py` script, providing a number of customers to generate. This should create a CSV file that can be uploaded to magento through the admin interface.