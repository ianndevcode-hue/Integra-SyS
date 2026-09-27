#!/usr/bin/env sh
# Usage: PHP=/path/to/php ./dev/serve.sh  → http://localhost:8080
cd "$(dirname "$0")/.." && ${PHP:-php} -S localhost:8080 -t public_html dev/router.php
