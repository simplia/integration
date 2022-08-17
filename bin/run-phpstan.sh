docker build -t integration-tmp .
docker build -t integration-tmp-phpstan ./vendor/simplia/integration/docker/phpstan/
docker run --rm -it integration-tmp-phpstan analyse /app/src
