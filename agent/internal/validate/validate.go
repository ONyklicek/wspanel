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
	return nil
}

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

// Frameworks supported by site presets.
var Frameworks = []string{"laravel", "symfony", "php"}

// SiteModes; only classic is implemented in phase 1.
var SiteModes = []string{"classic"}
