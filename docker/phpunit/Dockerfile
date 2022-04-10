FROM alpine/curl AS phpunit
RUN curl --location --output /usr/bin/phpunit https://phar.phpunit.de/phpunit-9.phar && chmod +x /usr/bin/phpunit
FROM integration-tmp
COPY --from=phpunit /usr/bin/phpunit /usr/bin/phpunit

ENTRYPOINT []
