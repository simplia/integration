docker build -t integration-tmp .
docker run --rm -it --entrypoint= integration-tmp vendor/bin/bref local --handler=index.php %1
