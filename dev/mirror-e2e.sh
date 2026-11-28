#!/usr/bin/env bash
# The mirror's end-to-end suite moved to dev/mirror-e2e/ (scenarios, JUnit,
# diagnostics): this runs it with the same arguments.
exec "$(dirname "$0")/mirror-e2e/run.sh" "$@"
