docker build -t integration-tmp .
docker run --rm -it --env-file=.env --entrypoint= integration-tmp vendor/bin/bref-local vendor/simplia/integration/handler.php "$1"
