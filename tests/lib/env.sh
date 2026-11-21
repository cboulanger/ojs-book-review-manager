# Shared settings and checks for the suites' run.sh scripts (source this file).
#
# Environment (defaults match dev/ojs-dev.sh):
#   PODMAN          podman (docker works too)
#   OFR_CONTAINER   ojs-bookreviewmanager
#   OFR_E2E_URL     http://localhost:${OJS_PORT:-8080}/index.php/test   journal URL for E2E tests

TESTS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_DIR="$(dirname "$TESTS_DIR")"
PODMAN="${PODMAN:-podman}"
OFR_CONTAINER="${OFR_CONTAINER:-ojs-bookreviewmanager}"
OFR_E2E_URL="${OFR_E2E_URL:-http://localhost:${OJS_PORT:-8080}/index.php/test}"
# Where the plugin is mounted in the container (see dev/ojs-dev.sh)
OJS_DIR=/var/www/html
PLUGIN_IN_CONTAINER="$OJS_DIR/plugins/generic/bookReviewManager"
# Pinned test tools (also installed by dev/Containerfile)
PHPUNIT_VERSION=11.5.46

export PODMAN OFR_CONTAINER OFR_E2E_URL

# Run a command in the container (as www-data, so that OJS cache files stay writable for Apache)
in_container() {
	"$PODMAN" exec -u www-data -w "$OJS_DIR" "$OFR_CONTAINER" "$@"
}

in_container_root() {
	"$PODMAN" exec -w "$OJS_DIR" "$OFR_CONTAINER" "$@"
}

require_container() {
	if ! "$PODMAN" container inspect -f '{{.State.Running}}' "$OFR_CONTAINER" 2>/dev/null | grep -q true; then
		echo "The dev container '$OFR_CONTAINER' is not running. Start it with: dev/ojs-dev.sh up" >&2
		exit 3
	fi
}

require_node() {
	if ! node -e 'process.exit(Number(process.versions.node.split(".")[0]) >= 20 ? 0 : 1)' 2>/dev/null; then
		echo "Node.js 20 or later is required." >&2
		exit 3
	fi
}

# PHPUnit in the container (images built from dev/Containerfile have it already)
ensure_phpunit() {
	if in_container_root sh -c "phpunit --version 2>/dev/null | grep -q ' $PHPUNIT_VERSION '"; then
		return
	fi
	echo "Installing PHPUnit $PHPUNIT_VERSION in the container..." >&2
	in_container_root sh -c "curl -fsSL -o /usr/local/bin/phpunit https://phar.phpunit.de/phpunit-$PHPUNIT_VERSION.phar && chmod +x /usr/local/bin/phpunit" >&2
}

# The API needs api_key_secret, and Apache must pass the Authorization header to PHP.
# Images built from the current dev/Containerfile have both; older containers are patched.
ensure_api_ready() {
	if in_container_root grep -q '^api_key_secret = ""' config.inc.php; then
		echo "Setting api_key_secret in the container's config.inc.php" >&2
		in_container_root sed -i 's/^api_key_secret = ""/api_key_secret = "ojs-dev-api-key-secret"/' config.inc.php
	fi
	if ! in_container_root test -f /etc/apache2/conf-enabled/authorization-header.conf; then
		echo "Passing the Authorization header through Apache in the container" >&2
		in_container_root sh -c "echo 'SetEnvIf Authorization \"(.*)\" HTTP_AUTHORIZATION=\$1' > /etc/apache2/conf-enabled/authorization-header.conf && apache2ctl -k graceful 2>/dev/null"
		sleep 1
	fi
}
