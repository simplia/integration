docker build -t integration-tmp .
docker run --rm -it --entrypoint= integration-tmp vendor/bin/bref local --handler=vendor/simplia/integration/handler.php %1
