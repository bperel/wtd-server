### Setup

The fastest way to start the project is to use the docker-compose template. In that case, Docker Compose 1.11.0-rc1+ is required.

#### Web server setup

Copy `config/roles.base.ini` and rename the copy to `config/roles.ini`. Edit `config/roles.ini` to set the application role passwords:
* The `ducksmanager` and `whattheduck` roles are only authorized to use the services prefixed with `/collection/`
* `rawsql` is only authorized to use the services prefixed with `/rawsql`

#### Database setup

If you wish to customize the names of the containers, the port bindings or the database credentials, edit `docker-compose.yml`. 

### Run !

#### Start the project

```bash
docker-compose up --build -d && watch -n 1 'docker ps | grep " second"'
```

Creating the containers should take less than a minute. 

#### Create database schemas

Once the containers are started, run the following command to generate the DB config files and create the schemas in the databases :
```bash
docker exec -it web /bin/bash -c /var/www/html/dm-server/scripts/create-schemas.sh
```
(considering `web` is the name of the running Web container)


### Maintain

#### Updating the code in the container

Browse to the path of the source on the host, then run: 
```bash
scripts/deploy/deploy-app.sh web
```


### Tasks

#### Reset the demo user

```bash
docker exec -i web /bin/bash dm-server/scripts/call-service.sh admin /user/resetDemo
```