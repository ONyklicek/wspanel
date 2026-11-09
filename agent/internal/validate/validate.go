// Package validate holds the parameter validators used by every operation.
// Nothing reaches the system unless it passed one of these.
package validate

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"regexp"
	"slices"
	"strings"

	"wspanel/agent/internal/protocol"
)

var (
	labelRe = regexp.MustCompile(`^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$`)
	tldRe   = regexp.MustCompile(`^([a-z]{2,63}|xn--[a-z0-9-]{1,59})$`)
)

var clientGroupRe = regexp.MustCompile(`^wsc_[1-9][0-9]{0,8}$`)

// ClientGroup accepts a client's Linux group name: wsc_<id>.
func ClientGroup(field, g string) error {
	if !clientGroupRe.MatchString(g) {
		return protocol.Invalid(field + ": must be wsc_<number>")
	}
	return nil
}

// Domain accepts a lowercase ASCII hostname with at least two labels.
// IDNs must be passed in punycode (the panel converts them).
func Domain(d string) error {
	if d == "" || len(d) > 253 {
		return protocol.Invalid("domain: length must be 1-253")
	}
	if d != strings.ToLower(d) {
		return protocol.Invalid("domain: must be lowercase")
	}
	labels := strings.Split(d, ".")
	if len(labels) < 2 {
		return protocol.Invalid("domain: needs at least two labels")
	}
	for _, l := range labels {
		if !labelRe.MatchString(l) {
			return protocol.Invalid(fmt.Sprintf("domain: invalid label %q", l))
		}
	}
	if !tldRe.MatchString(labels[len(labels)-1]) {
		return protocol.Invalid("domain: invalid top-level label")
	}
	if d == PanelInternalHost {
		return protocol.Invalid("domain: reserved for the panel")
	}
	return nil
}

// PanelInternalHost is the name the web tier knows the panel by; no site may have it.
const PanelInternalHost = "wspanel-panel.internal"

// OneOf checks that v is one of allowed.
func OneOf(field, v string, allowed []string) error {
	if !slices.Contains(allowed, v) {
		return protocol.Invalid(fmt.Sprintf("%s: %q not in %v", field, v, allowed))
	}
	return nil
}

// SiteUser derives the Linux system user for a site: web_<domain> with dots
// replaced by underscores. Domains never contain '_', so the mapping is
// injective. Names longer than 32 chars are truncated and suffixed with a hash.
func SiteUser(domain string) string {
	name := "web_" + strings.ReplaceAll(domain, ".", "_")
	if len(name) <= 32 {
		return name
	}
	sum := sha256.Sum256([]byte(domain))
	return name[:25] + "_" + hex.EncodeToString(sum[:])[:6]
}

var dbNameRe = regexp.MustCompile(`^[a-z][a-z0-9_]{1,47}$`)

var reservedDBNames = map[string]bool{
	"mysql": true, "information_schema": true, "performance_schema": true, "sys": true, "root": true,
	"postgres": true, "template0": true, "template1": true, "public": true, "pg_signal_backend": true,
	"mariadb": true, "debian_sys_maint": true, "panel": true,
}

// DBName validates a database / database user name (same rules for both,
// valid unquoted in MariaDB and PostgreSQL).
func DBName(field, n string) error {
	if !dbNameRe.MatchString(n) || strings.HasPrefix(n, "pg_") || reservedDBNames[n] {
		return protocol.Invalid(field + ": must match ^[a-z][a-z0-9_]{1,47}$ and not be a reserved name")
	}
	return nil
}

// Frameworks by family. PHP frameworks run in classic (PHP-FPM) or worker
// (FrankenPHP) mode, Node frameworks in node mode, Python ones in python mode.
var (
	PHPFrameworks    = []string{"laravel", "symfony", "php"}
	WorkerFrameworks = []string{"laravel", "symfony"} // need a worker entry point (Octane / Symfony Runtime)
	NodeFrameworks   = []string{"nuxt", "next", "node"}
	PythonFrameworks = []string{"django", "flask", "fastapi", "python"}
	Frameworks       = append(append(append([]string{}, PHPFrameworks...), NodeFrameworks...), PythonFrameworks...)
)

// SiteModes: classic = PHP-FPM, worker = FrankenPHP instance, node = Node SSR
// process, python = a Python app server (gunicorn / uvicorn).
var SiteModes = []string{"classic", "worker", "node", "python"}

// AppMode: the site is its own long-running process on an agent-allocated
// port (two slots for zero-downtime deploys).
func AppMode(mode string) bool { return mode == "node" || mode == "python" }

// FrameworksForMode lists the frameworks a mode can run.
func FrameworksForMode(mode string) []string {
	switch mode {
	case "classic":
		return PHPFrameworks
	case "worker":
		return WorkerFrameworks
	case "node":
		return NodeFrameworks
	case "python":
		return PythonFrameworks
	}
	return nil
}
