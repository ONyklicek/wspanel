package ops

import (
	"slices"
	"testing"
)

// PHP 8.5 has OPcache built in: sury publishes no php8.5-opcache, and asking
// apt for it fails the whole install.
func TestPHPInstallPackagesOpcache(t *testing.T) {
	if got := phpInstallPackages("8.4", nil); !slices.Contains(got, "php8.4-opcache") {
		t.Fatalf("8.4 without its opcache package: %v", got)
	}
	got := phpInstallPackages("8.5", []string{"mariadb"})
	if slices.Contains(got, "php8.5-opcache") {
		t.Fatalf("8.5 asks for php8.5-opcache: %v", got)
	}
	if !slices.Contains(got, "php8.5-fpm") || !slices.Contains(got, "php8.5-mysql") {
		t.Fatalf("8.5 packages: %v", got)
	}
}
