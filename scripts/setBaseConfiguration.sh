#!/bin/bash

removeSolr() {
  if [[ $1 == 'n' || $1 == 'no' ]]; then
    rm -rf .ddev/solr .ddev/docker-compose.solr.yaml
    sed -i '' "s/post-start:/# post-start:/" .ddev/config.yaml >/dev/null
    sed -i '' "s/  - exec-host: ddev solrcollection/#  -exec-host: ddev solrcollection/" .ddev/config.yaml >/dev/null
    echo 'Solr removed from ddev.'
  elif [[ $1 == 'y' || $1 == 'yes' ]]; then
    echo "You're all set."
  else 
    echo "Answer should be y, yes, n, no."
    read answer

    removeSolr $answer
  fi
}

if ! grep -q "\[client-code\]" .ddev/config.yaml; then
  exit 0
fi

echo
echo 'Client code?'
read client_code
sed -i '' "s/\[client-code\]/$client_code/" .ddev/config.yaml >/dev/null
echo

echo 'Project name?'
read project_name
sed -i '' "s/\[pantheon-site-name\]/$project_name/" .ddev/config.yaml >/dev/null
echo

echo 'Do you want solr installed? (y/n)'
read answer
echo
removeSolr $answer
