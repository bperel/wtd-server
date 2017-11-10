#!/usr/bin/env bash

deploy() {
  docker cp scripts ${container_name}:${webdir} \
  && docker exec ${container_name} /bin/bash ${webdir}/scripts/deploy/backup-app.sh \
  && docker exec ${container_name} /bin/bash -c "rm -rf ${webdir}_new && mkdir -p ${webdir}_new" \
  \
  && for f in .htaccess app assets scripts test index.php composer.json docker-compose.yml; \
  do \
    docker cp ${f} ${container_name}:${webdir}_new; \
  done \
  \
  && docker exec ${container_name} /bin/bash -c "echo `git rev-parse HEAD` > ${webdir}_new/deployment_commit_id.txt" \
  && docker exec -it ${container_name} /bin/bash -x ${webdir}_new/scripts/deploy/apply-app.sh
}

container_name=$1

if [ -z "$container_name" ]; then
	echo "No container name provided. Usage : $0 <container_name>"
	exit 1
fi

webdir=/var/www/html/dm-server

deploy
