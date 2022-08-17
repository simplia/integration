docker build -t integration-tmp .
docker build -t integration-tmp-tests ./vendor/simplia/integration/docker/phpunit/
docker run --rm -it integration-tmp-tests phpunit tests --bootstrap vendor/autoload.php
